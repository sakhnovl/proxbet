<?php

declare(strict_types=1);

/**
 * Telegram Bot API transport helpers.
 *
 * All functions throw RuntimeException on transport/HTTP/JSON errors.
 *
 * @return array<string,mixed>
 */
function tgRequest(string $apiBase, string $method, array $payload): array
{
    $url = $apiBase . '/' . $method;

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
    ]);

    $raw = curl_exec($ch);
    $errNo = curl_errno($ch);
    $err = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('cURL error: ' . $errNo . ' ' . $err);
    }
    if ($status >= 400) {
        throw new RuntimeException('HTTP ' . $status . ': ' . $raw);
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Telegram response is not JSON: ' . $raw);
    }

    return $decoded;
}

function tgSendMessage(string $apiBase, int $chatId, string $text, array $opts = []): void
{
    $payload = array_merge([
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ], $opts);

    tgRequest($apiBase, 'sendMessage', $payload);
}

function tgEditMessage(string $apiBase, int $chatId, int $messageId, string $text, array $opts = []): void
{
    $payload = array_merge([
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ], $opts);

    try {
        tgRequest($apiBase, 'editMessageText', $payload);
    } catch (RuntimeException $e) {
        // Telegram returns HTTP 400 for a no-op edit:
        // "Bad Request: message is not modified: specified new message content and reply markup are exactly the same..."
        // This is not an operational error for us, so we silently ignore it.
        if (str_contains($e->getMessage(), 'message is not modified')) {
            return;
        }
        throw $e;
    }
}

function tgAnswerCallback(string $apiBase, string $callbackQueryId, string $text = ''): void
{
    tgRequest($apiBase, 'answerCallbackQuery', [
        'callback_query_id' => $callbackQueryId,
        'text' => $text,
        'show_alert' => false,
    ]);
}
