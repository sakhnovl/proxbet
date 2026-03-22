<?php

declare(strict_types=1);

/**
 * Alertmanager webhook handler (Protected)
 * 
 * Receives alerts from Prometheus Alertmanager and sends to Telegram.
 * Should be protected with a secret token to prevent unauthorized access.
 */

require_once __DIR__ . '/bootstrap/autoload.php';
require_once __DIR__ . '/bootstrap/runtime.php';
require_once __DIR__ . '/bootstrap/http.php';
require_once __DIR__ . '/core/StructuredLogger.php';

use Proxbet\Core\StructuredLogger;

$logger = StructuredLogger::getInstance();
$logger->generateCorrelationId();

proxbet_bootstrap_http_endpoint(['POST'], ['Authorization', 'Content-Type', 'X-Webhook-Secret']);

// ── Authentication ─────────────────────────────────────────────────────────

$webhookSecret = (string) (getenv('ALERT_WEBHOOK_SECRET') ?: '');

if ($webhookSecret !== '') {
    $providedSecret = proxbet_extract_bearer_token();
    
    // Also check X-Webhook-Secret header (alternative method)
    $webhookSecretHeader = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';
    if ($webhookSecretHeader !== '') {
        $providedSecret = $webhookSecretHeader;
    }
    
    // Validate secret
    if ($providedSecret === '' || !hash_equals($webhookSecret, $providedSecret)) {
        http_response_code(401);
        $logger->warning('Unauthorized webhook attempt', [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);
        echo json_encode(['error' => 'Unauthorized'], JSON_THROW_ON_ERROR);
        exit;
    }
}

// ── Process Webhook ────────────────────────────────────────────────────────

// Get webhook payload
$payload = file_get_contents('php://input');
if ($payload === false) {
    http_response_code(400);
    $logger->error('Failed to read webhook payload');
    echo json_encode(['error' => 'Failed to read payload'], JSON_THROW_ON_ERROR);
    exit;
}

$data = json_decode($payload, true);
if ($data === null) {
    http_response_code(400);
    $logger->error('Invalid JSON payload');
    echo json_encode(['error' => 'Invalid JSON'], JSON_THROW_ON_ERROR);
    exit;
}

$logger->info('Received alert webhook', ['alerts_count' => count($data['alerts'] ?? [])]);

// Get priority from query string
$priority = $_GET['priority'] ?? 'normal';
$isCritical = $priority === 'high';

// Format alerts for Telegram
$messages = [];
foreach ($data['alerts'] ?? [] as $alert) {
    $status = $alert['status'] ?? 'unknown';
    $labels = $alert['labels'] ?? [];
    $annotations = $alert['annotations'] ?? [];
    
    $alertName = $labels['alertname'] ?? 'Unknown';
    $severity = $labels['severity'] ?? 'unknown';
    $component = $labels['component'] ?? 'unknown';
    $summary = $annotations['summary'] ?? 'No summary';
    $description = $annotations['description'] ?? 'No description';
    
    // Format emoji based on status and severity
    $emoji = '⚠️';
    if ($status === 'resolved') {
        $emoji = '✅';
    } elseif ($severity === 'critical') {
        $emoji = '🚨';
    }
    
    $message = sprintf(
        "%s *%s*\n\n" .
        "Status: %s\n" .
        "Severity: %s\n" .
        "Component: %s\n\n" .
        "%s\n\n" .
        "%s",
        $emoji,
        $alertName,
        strtoupper($status),
        strtoupper($severity),
        $component,
        $summary,
        $description
    );
    
    $messages[] = $message;
}

// Send to Telegram
if (count($messages) > 0) {
    $botToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? '';
    $adminChatId = $_ENV['TELEGRAM_ADMIN_ID'] ?? '';
    
    if ($botToken === '' || $adminChatId === '') {
        $logger->error('Telegram credentials not configured');
        http_response_code(500);
        exit;
    }
    
    foreach ($messages as $message) {
        $url = sprintf('https://api.telegram.org/bot%s/sendMessage', $botToken);
        
        $postData = [
            'chat_id' => $adminChatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true,
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            $logger->error('Failed to send alert to Telegram', [
                'http_code' => $httpCode,
                'response' => $response,
            ]);
        } else {
            $logger->info('Alert sent to Telegram', ['alert' => substr($message, 0, 100)]);
        }
    }
}

http_response_code(200);
echo json_encode(['status' => 'ok', 'processed' => count($messages)]);
