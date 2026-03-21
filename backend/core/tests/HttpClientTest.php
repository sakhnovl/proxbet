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
    private HttpClient $client;

    protected function setUp(): void
    {
        $this->client = new HttpClient();
    }

    public function testGetRequestSuccess(): void
    {
        // Test with a reliable endpoint
        $response = $this->client->get('https://httpbin.org/get');
        
        $this->assertIsArray($response);
        $this->assertArrayHasKey('url', $response);
    }

    public function testPostRequestSuccess(): void
    {
        $data = ['test' => 'value', 'number' => 42];
        $response = $this->client->post('https://httpbin.org/post', $data);
        
        $this->assertIsArray($response);
        $this->assertArrayHasKey('json', $response);
        $this->assertEquals('value', $response['json']['test']);
        $this->assertEquals(42, $response['json']['number']);
    }

    public function testRequestWithCustomHeaders(): void
    {
        $headers = [
            'X-Custom-Header' => 'test-value',
            'X-Request-ID' => 'test-123',
        ];
        
        $response = $this->client->get('https://httpbin.org/headers', $headers);
        
        $this->assertIsArray($response);
        $this->assertArrayHasKey('headers', $response);
        $this->assertArrayHasKey('X-Custom-Header', $response['headers']);
    }

    public function testRequestWithTimeout(): void
    {
        $client = new HttpClient(timeout: 1);
        
        // This should timeout
        $this->expectException(ApiException::class);
        $this->expectExceptionMessageMatches('/timeout|timed out/i');
        
        $client->get('https://httpbin.org/delay/5');
    }

    public function testInvalidUrlThrowsException(): void
    {
        $this->expectException(ApiException::class);
        
        $this->client->get('not-a-valid-url');
    }

    public function testNonExistentDomainThrowsException(): void
    {
        $this->expectException(ApiException::class);
        
        $this->client->get('https://this-domain-definitely-does-not-exist-12345.com');
    }

    public function testRetryLogicOn500Error(): void
    {
        // httpbin.org/status/500 returns 500 error
        $this->expectException(ApiException::class);
        
        $client = new HttpClient(maxRetries: 2, retryDelay: 100);
        $client->get('https://httpbin.org/status/500');
    }

    public function testUserAgentIsSet(): void
    {
        $response = $this->client->get('https://httpbin.org/user-agent');
        
        $this->assertIsArray($response);
        $this->assertArrayHasKey('user-agent', $response);
        $this->assertStringContainsString('Proxbet', $response['user-agent']);
    }

    public function testJsonResponseParsing(): void
    {
        $response = $this->client->get('https://httpbin.org/json');
        
        $this->assertIsArray($response);
        // httpbin.org/json returns a sample JSON object
        $this->assertNotEmpty($response);
    }

    public function testPostWithEmptyBody(): void
    {
        $response = $this->client->post('https://httpbin.org/post', []);
        
        $this->assertIsArray($response);
        $this->assertArrayHasKey('json', $response);
    }

    public function testConnectionReuseWithKeepAlive(): void
    {
        // Make multiple requests to test keep-alive
        $response1 = $this->client->get('https://httpbin.org/get');
        $response2 = $this->client->get('https://httpbin.org/get');
        
        $this->assertIsArray($response1);
        $this->assertIsArray($response2);
    }

    public function testResponseStatusCodeHandling(): void
    {
        // Test 404 error
        $this->expectException(ApiException::class);
        $this->expectExceptionMessageMatches('/404|not found/i');
        
        $this->client->get('https://httpbin.org/status/404');
    }

    public function testLargeResponseHandling(): void
    {
        // Request a large response (10KB)
        $response = $this->client->get('https://httpbin.org/bytes/10240');
        
        $this->assertIsArray($response);
    }

    public function testRedirectFollowing(): void
    {
        // httpbin.org/redirect/1 redirects once
        $response = $this->client->get('https://httpbin.org/redirect/1');
        
        $this->assertIsArray($response);
        $this->assertArrayHasKey('url', $response);
    }
}
