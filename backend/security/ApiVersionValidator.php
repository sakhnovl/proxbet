<?php

declare(strict_types=1);

namespace Proxbet\Security;

/**
 * API version validator
 * Supports API versioning via header or URL path
 */
class ApiVersionValidator
{
    private const SUPPORTED_VERSIONS = ['v1'];
    private const DEFAULT_VERSION = 'v1';
    /**
     * Get API version from request
     * Checks: 1) X-API-Version header, 2) Accept header, 3) URL path
     * 
     * @return string API version (e.g., 'v1')
     */
    public function getRequestedVersion(): string
    {
        // Check X-API-Version header
        if (isset($_SERVER['HTTP_X_API_VERSION'])) {
            $version = $this->normalizeVersion($_SERVER['HTTP_X_API_VERSION']);
            if ($this->isVersionSupported($version)) {
                return $version;
            }
        }

        // Check Accept header (e.g., application/vnd.proxbet.v1+json)
        if (isset($_SERVER['HTTP_ACCEPT'])) {
            $accept = $_SERVER['HTTP_ACCEPT'];
            if (preg_match('/application\/vnd\.proxbet\.(v\d+)\+json/', $accept, $matches)) {
                $version = $matches[1];
                if ($this->isVersionSupported($version)) {
                    return $version;
                }
            }
        }

        // Check URL path (e.g., /api/v1/matches)
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (preg_match('#/api/(v\d+)/#', $uri, $matches)) {
            $version = $matches[1];
            if ($this->isVersionSupported($version)) {
                return $version;
            }
        }

        return self::DEFAULT_VERSION;
    }

    /**
     * Validate if version is supported
     * 
     * @param string $version Version to check
     * @return bool True if supported
     */
    public function isVersionSupported(string $version): bool
    {
        return in_array($version, self::SUPPORTED_VERSIONS, true);
    }

    /**
     * Check if version is deprecated
     * 
     * @param string $version Version to check
     * @return bool True if deprecated
     */
    public function isVersionDeprecated(string $version): bool
    {
        return false;
    }

    /**
     * Add version headers to response
     * 
     * @param string $version Current API version
     */
    public function addVersionHeaders(string $version): void
    {
        header('X-API-Version: ' . $version);
        
        if ($this->isVersionDeprecated($version)) {
            header('X-API-Deprecated: true');
            header('X-API-Sunset: ' . $this->getDeprecationDate($version));
        }

        // Add supported versions
        header('X-API-Supported-Versions: ' . implode(', ', self::SUPPORTED_VERSIONS));
    }

    /**
     * Send version not supported error
     * 
     * @param string $requestedVersion Requested version
     */
    public function sendVersionNotSupported(string $requestedVersion): void
    {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'API version not supported',
            'requested_version' => $requestedVersion,
            'supported_versions' => self::SUPPORTED_VERSIONS,
        ], JSON_THROW_ON_ERROR);
        exit;
    }

    /**
     * Normalize version string
     */
    private function normalizeVersion(string $version): string
    {
        $version = strtolower(trim($version));
        if (!str_starts_with($version, 'v')) {
            $version = 'v' . $version;
        }
        return $version;
    }

    /**
     * Get deprecation date for version
     */
    private function getDeprecationDate(string $version): string
    {
        // Return sunset date (6 months from now as example)
        return date('Y-m-d', strtotime('+6 months'));
    }

    /**
     * Get supported versions
     * 
     * @return array<string>
     */
    public function getSupportedVersions(): array
    {
        return self::SUPPORTED_VERSIONS;
    }
}
