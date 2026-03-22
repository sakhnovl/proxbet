<?php

declare(strict_types=1);

namespace Proxbet\Tests\E2E;

use PHPUnit\Framework\TestCase;
use Proxbet\Tests\Support\HttpRuntimeAwareTrait;

/**
 * E2E tests for complete match flow from parsing to notification.
 * These tests require actual database and services running.
 */
final class FullMatchFlowTest extends TestCase
{
    use HttpRuntimeAwareTrait;

    private string $apiBaseUrl;
    private string $adminToken;

    protected function setUp(): void
    {
        $this->apiBaseUrl = getenv('TEST_API_URL') ?: 'http://localhost:8080';
        $this->adminToken = (string) (
            getenv('TEST_ADMIN_TOKEN')
            ?: getenv('ADMIN_API_TOKEN')
            ?: getenv('ADMIN_PASSWORD')
            ?: ''
        );
        
        // Skip if not in E2E test environment
        if (getenv('RUN_E2E_TESTS') !== '1') {
            $this->markTestSkipped('E2E tests disabled. Set RUN_E2E_TESTS=1 to enable.');
        }

        if (!$this->isRuntimeAvailable($this->apiBaseUrl)) {
            $this->markTestSkipped('E2E tests require an available HTTP runtime. Configure TEST_API_URL or start the app.');
        }
    }

    public function testApiHealthCheck(): void
    {
        $response = $this->makeRequest('GET', '/backend/healthz.php');
        
        $this->assertEquals(200, $response['status']);
        $data = json_decode($response['body'], true);
        $this->assertEquals('ok', $data['status']);
    }

    public function testGetActiveMatches(): void
    {
        $response = $this->makeRequest('GET', '/backend/api.php?action=matches');
        
        $this->assertEquals(200, $response['status']);
        $data = json_decode($response['body'], true);
        $this->assertIsArray($data);
    }

    public function testAdminBanManagement(): void
    {
        if ($this->adminToken === '') {
            $this->markTestSkipped('Admin E2E tests require TEST_ADMIN_TOKEN or runtime admin credentials.');
        }

        // Create ban
        $banData = [
            'country' => 'Test Country E2E',
            'liga' => 'Test League',
            'home' => 'Test Team',
            'away' => null,
        ];

        $response = $this->makeRequest(
            'POST',
            '/backend/admin/api.php?action=add_ban',
            $banData,
            ['Authorization: Bearer ' . $this->adminToken]
        );

        $this->assertEquals(200, $response['status']);
        $result = json_decode($response['body'], true);
        $this->assertTrue($result['success'] ?? false);
        $banId = $result['id'] ?? null;
        $this->assertNotNull($banId);

        // List bans
        $response = $this->makeRequest(
            'GET',
            '/backend/admin/api.php?action=list_bans&limit=10&offset=0',
            null,
            ['Authorization: Bearer ' . $this->adminToken]
        );

        $this->assertEquals(200, $response['status']);
        $result = json_decode($response['body'], true);
        $this->assertIsArray($result['rows'] ?? []);

        // Delete ban
        if ($banId) {
            $response = $this->makeRequest(
                'POST',
                '/backend/admin/api.php?action=delete_ban',
                ['id' => $banId],
                ['Authorization: Bearer ' . $this->adminToken]
            );

            $this->assertEquals(200, $response['status']);
        }
    }

    public function testMetricsEndpoint(): void
    {
        $headers = [];
        $metricsToken = (string) getenv('TEST_METRICS_TOKEN');

        if ($metricsToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $metricsToken;
        }

        $response = $this->makeRequest('GET', '/backend/metrics.php', null, $headers);

        if ($metricsToken === '' && $response['status'] === 401) {
            $this->markTestSkipped('Metrics endpoint is protected in this environment. Set TEST_METRICS_TOKEN to validate it.');
        }

        $this->assertEquals(200, $response['status']);
        $this->assertStringContainsString('proxbet_', $response['body']);
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
