<?php

declare(strict_types=1);

namespace Proxbet\Core\Tests;

use PHPUnit\Framework\TestCase;
use Proxbet\Core\HttpClient;
use Proxbet\Core\Exceptions\ApiException;

/**
 * Unit tests for HttpClient - HTTP request handling with retry logic.
 */
final class HttpClientTest extends TestCase
{
    public function testGetRequestSuccess(): void
    {
        // Test with a reliable endpoint
        $response = HttpClient::getWithRetry('https://httpbin.org/get', 10000, 1);
        
        $this->assertIsArray($response);
        $this->assertTrue($response['ok']);
        $this->assertEquals(200, $response['status']);
        $this->assertStringContainsString('httpbin.org', $response['body']);
    }

    public function testGetJsonSuccess(): void
    {
        $response = HttpClient::getJson('https://httpbin.org/json', 10000, 1);
        
        $this->assertIsArray($response);
        $this->assertNotEmpty($response);
    }

    public function testRequestWithCustomUserAgent(): void
    {
        $response = HttpClient::getWithRetry('https://httpbin.org/user-agent', 10000, 1, 'CustomAgent/1.0');
        
        $this->assertTrue($response['ok']);
        $this->assertStringContainsString('CustomAgent', $response['body']);
    }

    public function testRequestWithTimeout(): void
    {
        // Short timeout should fail on delayed endpoint
        $response = HttpClient::getWithRetry('https://httpbin.org/delay/10', 1000, 0);
        
        $this->assertFalse($response['ok']);
        $this->assertNotNull($response['error']);
    }

    public function testInvalidUrlThrowsException(): void
    {
        $this->expectException(ApiException::class);
        
        HttpClient::getJson('not-a-valid-url', 5000, 0);
    }

    public function testNonExistentDomainThrowsException(): void
    {
        $this->expectException(ApiException::class);
        
        HttpClient::getJson('https://this-domain-definitely-does-not-exist-12345.com', 5000, 0);
    }

    public function testRetryLogicOn500Error(): void
    {
        // httpbin.org/status/500 returns 500 error, should retry
        $response = HttpClient::getWithRetry('https://httpbin.org/status/500', 5000, 2);
        
        $this->assertFalse($response['ok']);
        $this->assertEquals(500, $response['status']);
        $this->assertGreaterThan(1, $response['attempts']);
    }

    public function testUserAgentIsSet(): void
    {
        $response = HttpClient::getWithRetry('https://httpbin.org/user-agent', 10000, 1);
        
        $this->assertTrue($response['ok']);
        $this->assertStringContainsString('proxbets', $response['body']);
    }

    public function testJsonResponseParsing(): void
    {
        $response = HttpClient::getJson('https://httpbin.org/json', 10000, 1);
        
        $this->assertIsArray($response);
        $this->assertNotEmpty($response);
    }

    public function testGetJsonThrowsOnInvalidJson(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessageMatches('/not valid JSON/i');
        
        // This endpoint returns HTML, not JSON
        HttpClient::getJson('https://httpbin.org/html', 10000, 0);
    }

    public function testConnectionReuseWithKeepAlive(): void
    {
        // Make multiple requests to test keep-alive
        $response1 = HttpClient::getWithRetry('https://httpbin.org/get', 10000, 1);
        $response2 = HttpClient::getWithRetry('https://httpbin.org/get', 10000, 1);
        
        $this->assertTrue($response1['ok']);
        $this->assertTrue($response2['ok']);
    }

    public function testResponseStatusCodeHandling(): void
    {
        // Test 404 error - should not retry (client error)
        $response = HttpClient::getWithRetry('https://httpbin.org/status/404', 5000, 2);
        
        $this->assertFalse($response['ok']);
        $this->assertEquals(404, $response['status']);
        $this->assertEquals(1, $response['attempts']); // Should not retry on 404
    }

    public function testLargeResponseHandling(): void
    {
        // Request a large response (10KB)
        $response = HttpClient::getWithRetry('https://httpbin.org/bytes/10240', 10000, 1);
        
        $this->assertTrue($response['ok']);
        $this->assertGreaterThan(10000, strlen($response['body']));
    }

    public function testRedirectFollowing(): void
    {
        // httpbin.org/redirect/1 redirects once
        $response = HttpClient::getWithRetry('https://httpbin.org/redirect/1', 10000, 1);
        
        $this->assertTrue($response['ok']);
        $this->assertEquals(200, $response['status']);
    }
}
