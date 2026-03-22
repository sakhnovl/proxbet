<?php

declare(strict_types=1);

namespace Proxbet\Admin;

use Proxbet\Security\AuditLogger;

final class AdminAuthenticator
{
    public function __construct(
        private AuditLogger $auditLogger,
        private string $clientIp
    ) {
    }

    /**
     * @return array{token:string,source:string}
     */
    public function resolveConfiguredToken(): array
    {
        $token = trim((string) getenv('ADMIN_API_TOKEN'));
        if ($token !== '') {
            return ['token' => $token, 'source' => 'ADMIN_API_TOKEN'];
        }

        $legacyToken = trim((string) getenv('ADMIN_PASSWORD'));
        if ($legacyToken !== '') {
            return ['token' => $legacyToken, 'source' => 'ADMIN_PASSWORD'];
        }

        throw new \RuntimeException('Missing required env: ADMIN_API_TOKEN or ADMIN_PASSWORD');
    }

    public function authenticate(string $providedToken): string
    {
        if ($providedToken === '') {
            $this->auditLogger->logAuthAttempt(false, null, $this->clientIp, 'Missing bearer token');
            throw new \RuntimeException('Missing Authorization header. Use: Authorization: Bearer <token>');
        }

        $configured = $this->resolveConfiguredToken();
        if (!hash_equals($configured['token'], $providedToken)) {
            $this->auditLogger->logAuthAttempt(false, null, $this->clientIp, 'Invalid token');
            throw new \RuntimeException('Unauthorized.');
        }

        $adminId = 'admin-api';
        $this->auditLogger->logAuthAttempt(true, $adminId, $this->clientIp, $configured['source']);

        return $adminId;
    }
}
