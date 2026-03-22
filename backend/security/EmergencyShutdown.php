<?php

declare(strict_types=1);

namespace Proxbet\Security;

/**
 * Emergency shutdown mechanism for bot and services
 * Allows immediate shutdown in case of security incidents
 */
class EmergencyShutdown
{
    private string $shutdownFile;
    private string $reasonFile;

    public function __construct(string $shutdownFile = '/tmp/proxbet_shutdown.lock')
    {
        $this->shutdownFile = $shutdownFile;
        $this->reasonFile = $shutdownFile . '.reason';
    }

    /**
     * Trigger emergency shutdown
     */
    public function trigger(string $reason, int $adminId): bool
    {
        $data = [
            'timestamp' => time(),
            'reason' => $reason,
            'admin_id' => $adminId,
            'server' => gethostname(),
        ];

        // Create shutdown lock file
        if (file_put_contents($this->shutdownFile, '1') === false) {
            return false;
        }

        // Write reason
        file_put_contents(
            $this->reasonFile,
            json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );

        return true;
    }

    /**
     * Check if system is in shutdown mode
     */
    public function isActive(): bool
    {
        return file_exists($this->shutdownFile);
    }

    /**
     * Get shutdown reason
     * 
     * @return array<string,mixed>|null
     */
    public function getReason(): ?array
    {
        if (!file_exists($this->reasonFile)) {
            return null;
        }

        $content = file_get_contents($this->reasonFile);
        if ($content === false) {
            return null;
        }

        try {
            return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Lift emergency shutdown
     */
    public function lift(int $adminId): bool
    {
        $lifted = true;

        if (file_exists($this->shutdownFile)) {
            $lifted = unlink($this->shutdownFile);
        }

        if (file_exists($this->reasonFile)) {
            // Archive reason before deleting
            $reason = $this->getReason();
            if ($reason !== null) {
                $reason['lifted_at'] = time();
                $reason['lifted_by'] = $adminId;
                
                $archiveFile = $this->reasonFile . '.' . time() . '.archive';
                file_put_contents(
                    $archiveFile,
                    json_encode($reason, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
                );
            }

            $lifted = unlink($this->reasonFile) && $lifted;
        }

        return $lifted;
    }

    /**
     * Check and halt if shutdown is active
     */
    public function checkAndHalt(string $serviceName = 'service'): void
    {
        if ($this->isActive()) {
            $reason = $this->getReason();
            $message = 'Emergency shutdown active';
            
            if ($reason !== null) {
                $message .= ': ' . ($reason['reason'] ?? 'Unknown reason');
            }

            error_log("[{$serviceName}] {$message}");
            exit(1);
        }
    }
}
