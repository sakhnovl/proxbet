<?php

declare(strict_types=1);

namespace Proxbet\Tests\Security;

use PHPUnit\Framework\TestCase;
use Proxbet\Tests\Support\HttpRuntimeAwareTrait;

final class OperationalEndpointSecurityTest extends TestCase
{
    use HttpRuntimeAwareTrait;

    private string $apiBaseUrl;

    protected function setUp(): void
    {
        $this->apiBaseUrl = getenv('TEST_API_URL') ?: 'http://localhost:8080';

        if (getenv('RUN_SECURITY_TESTS') !== '1') {
            $this->markTestSkipped('Security tests disabled. Set RUN_SECURITY_TESTS=1 to enable.');
        }

        if (!$this->isRuntimeAvailable($this->apiBaseUrl)) {
            $this->markTestSkipped('Security tests require an available HTTP runtime. Configure TEST_API_URL or start the app.');
        }
    }

    public function testPublicHealthDoesNotExposeDiagnostics(): void
    {
        $response = $this->makeRequest('GET', '/backend/healthz.php');

        $this->assertContains($response['status'], [200, 503]);

        $payload = json_decode($response['body'], true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('status', $payload);
        $this->assertArrayHasKey('service', $payload);
        $this->assertArrayNotHasKey('checks', $payload);
        $this->assertArrayNotHasKey('database', $payload);
        $this->assertStringNotContainsString('disk', strtolower($response['body']));
        $this->assertStringNotContainsString('memory', strtolower($response['body']));
    }

    public function testProtectedHealthRejectsAnonymousAccessWhenCredentialsConfigured(): void
    {
        if (!$this->hasHealthCredentials()) {
            $this->markTestSkipped('TEST_HEALTH_USERNAME/TEST_HEALTH_PASSWORD not configured.');
        }

        $response = $this->makeRequest('GET', '/backend/healthz_enhanced.php');

        $this->assertContains($response['status'], [401, 403]);
    }

    public function testProtectedHealthAcceptsConfiguredBasicAuth(): void
    {
        if (!$this->hasHealthCredentials()) {
            $this->markTestSkipped('TEST_HEALTH_USERNAME/TEST_HEALTH_PASSWORD not configured.');
        }

        $credentials = base64_encode(
            (string) getenv('TEST_HEALTH_USERNAME') . ':' . (string) getenv('TEST_HEALTH_PASSWORD')
        );

        $response = $this->makeRequest(
            'GET',
            '/backend/healthz_enhanced.php',
            null,
            ['Authorization: Basic ' . $credentials]
        );

        $this->assertContains($response['status'], [200, 503]);
        $payload = json_decode($response['body'], true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('status', $payload);
    }

    public function testMetricsRejectsMissingTokenWhenConfigured(): void
    {
        if (!$this->hasMetricsToken()) {
            $this->markTestSkipped('TEST_METRICS_TOKEN not configured.');
        }

        $response = $this->makeRequest('GET', '/backend/metrics.php');

        $this->assertSame(401, $response['status']);
    }

    public function testMetricsAcceptsConfiguredBearerToken(): void
    {
        if (!$this->hasMetricsToken()) {
            $this->markTestSkipped('TEST_METRICS_TOKEN not configured.');
        }

        $response = $this->makeRequest(
            'GET',
            '/backend/metrics.php',
            null,
            ['Authorization: Bearer ' . (string) getenv('TEST_METRICS_TOKEN')]
        );

        $this->assertSame(200, $response['status']);
        $this->assertStringNotContainsString('Unauthorized', $response['body']);
    }

    public function testAlertWebhookRejectsMissingSecretWhenConfigured(): void
    {
        if (!$this->hasAlertWebhookSecret()) {
            $this->markTestSkipped('TEST_ALERT_WEBHOOK_SECRET not configured.');
        }

        $response = $this->makeRequest(
            'POST',
            '/backend/alert_webhook.php',
            ['alerts' => []]
        );

        $this->assertSame(401, $response['status']);
    }

    public function testAlertWebhookAcceptsConfiguredSecretHeader(): void
    {
        if (!$this->hasAlertWebhookSecret()) {
            $this->markTestSkipped('TEST_ALERT_WEBHOOK_SECRET not configured.');
        }

        $response = $this->makeRequest(
            'POST',
            '/backend/alert_webhook.php',
            ['alerts' => []],
            ['X-Webhook-Secret: ' . (string) getenv('TEST_ALERT_WEBHOOK_SECRET')]
        );

        $this->assertSame(200, $response['status']);
        $payload = json_decode($response['body'], true);
        $this->assertIsArray($payload);
        $this->assertSame('ok', $payload['status'] ?? null);
        $this->assertSame(0, $payload['processed'] ?? null);
    }

    private function hasHealthCredentials(): bool
    {
        return (string) getenv('TEST_HEALTH_USERNAME') !== ''
            && (string) getenv('TEST_HEALTH_PASSWORD') !== '';
    }

    private function hasMetricsToken(): bool
    {
        return (string) getenv('TEST_METRICS_TOKEN') !== '';
    }

    private function hasAlertWebhookSecret(): bool
    {
        return (string) getenv('TEST_ALERT_WEBHOOK_SECRET') !== '';
    }

    /**
     * @param array<string,mixed>|null $data
     * @param array<int,string> $headers
     * @return array{status:int,body:string}
     */
    private function makeRequest(
        string $method,
        string $path,
        ?array $data = null,
        array $headers = []
    ): array {
        $url = $this->apiBaseUrl . $path;
        $ch = curl_init($url);

        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }

        $defaultHeaders = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => array_merge($defaultHeaders, $headers),
        ]);

        if ($method === 'POST' && $data !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status' => $status,
            'body' => is_string($response) ? $response : '',
        ];
    }
}
