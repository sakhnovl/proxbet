<?php

declare(strict_types=1);

namespace Proxbet\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Security test suite covering OWASP Top 10 vulnerabilities.
 */
final class SecurityTestSuite extends TestCase
{
    private string $apiBaseUrl;

    protected function setUp(): void
    {
        $this->apiBaseUrl = getenv('TEST_API_URL') ?: 'http://localhost:8080';
        
        if (getenv('RUN_SECURITY_TESTS') !== '1') {
            $this->markTestSkipped('Security tests disabled. Set RUN_SECURITY_TESTS=1 to enable.');
        }
    }

    public function testSqlInjectionProtection(): void
    {
        // Test SQL injection in query parameters
        $maliciousInputs = [
            "' OR '1'='1",
            "1' UNION SELECT * FROM users--",
            "'; DROP TABLE matches;--",
        ];

        foreach ($maliciousInputs as $input) {
            $response = $this->makeRequest('GET', "/backend/api.php?action=matches&id=" . urlencode($input));
            
            // Should not return 500 error or expose SQL errors
            $this->assertNotEquals(500, $response['status']);
            $this->assertStringNotContainsString('SQL', $response['body']);
            $this->assertStringNotContainsString('syntax error', $response['body']);
        }
    }

    public function testXssProtection(): void
    {
        // Test XSS in various inputs
        $xssPayloads = [
            '<script>alert("XSS")</script>',
            '<img src=x onerror=alert("XSS")>',
            'javascript:alert("XSS")',
        ];

        foreach ($xssPayloads as $payload) {
            $response = $this->makeRequest('GET', "/backend/api.php?search=" . urlencode($payload));
            
            // Response should not contain unescaped script tags
            $this->assertStringNotContainsString('<script>', $response['body']);
            $this->assertStringNotContainsString('onerror=', $response['body']);
        }
    }

    public function testSecurityHeaders(): void
    {
        $response = $this->makeRequestWithHeaders('GET', '/backend/api.php');
        
        // Check for security headers
        $this->assertArrayHasKey('x-content-type-options', $response['headers']);
        $this->assertArrayHasKey('x-frame-options', $response['headers']);
        $this->assertEquals('nosniff', strtolower($response['headers']['x-content-type-options']));
    }

    public function testRateLimiting(): void
    {
        // Make multiple rapid requests
        $responses = [];
        for ($i = 0; $i < 100; $i++) {
            $responses[] = $this->makeRequest('GET', '/backend/api.php?action=matches');
        }

        // Should eventually get rate limited (429 status)
        $rateLimited = false;
        foreach ($responses as $response) {
            if ($response['status'] === 429) {
                $rateLimited = true;
                break;
            }
        }

        $this->assertTrue($rateLimited, 'Rate limiting should trigger after many requests');
    }

    public function testAuthenticationBypass(): void
    {
        // Try to access admin endpoint without token
        $response = $this->makeRequest('GET', '/backend/admin/api.php?action=list_bans');
        
        $this->assertNotEquals(200, $response['status']);
        $this->assertContains($response['status'], [401, 403]);
    }

    public function testInvalidTokenRejection(): void
    {
        // Try with invalid token
        $response = $this->makeRequest(
            'GET',
            '/backend/admin/api.php?action=list_bans',
            null,
            ['Authorization: Bearer invalid_token_12345']
        );
        
        $this->assertNotEquals(200, $response['status']);
    }

    public function testDirectoryTraversal(): void
    {
        // Test directory traversal attempts
        $traversalAttempts = [
            '../../../etc/passwd',
            '..\\..\\..\\windows\\system32\\config\\sam',
            '....//....//....//etc/passwd',
        ];

        foreach ($traversalAttempts as $attempt) {
            $response = $this->makeRequest('GET', '/backend/api.php?file=' . urlencode($attempt));
            
            // Should not expose file contents
            $this->assertStringNotContainsString('root:', $response['body']);
            $this->assertStringNotContainsString('[boot loader]', $response['body']);
        }
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
        $result = $this->makeRequestWithHeaders($method, $path, $data, $headers);
        return ['status' => $result['status'], 'body' => $result['body']];
    }

    /**
     * @param array<string,mixed>|null $data
     * @param array<int,string> $headers
     * @return array{status:int,body:string,headers:array<string,string>}
     */
    private function makeRequestWithHeaders(
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

        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$responseHeaders) {
                $len = strlen($header);
                $header = explode(':', $header, 2);
                if (count($header) < 2) {
                    return $len;
                }
                $responseHeaders[strtolower(trim($header[0]))] = trim($header[1]);
                return $len;
            },
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
            'headers' => $responseHeaders,
        ];
    }
}
