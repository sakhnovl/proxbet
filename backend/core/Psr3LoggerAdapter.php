<?php

declare(strict_types=1);

namespace Proxbet\Core;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * PSR-3 Logger Interface Adapter
 * Adapts StructuredLogger to PSR-3 standard
 */
class Psr3LoggerAdapter implements LoggerInterface
{
    private StructuredLogger $logger;

    public function __construct(StructuredLogger $logger)
    {
        $this->logger = $logger;
    }

    public function emergency($message, array $context = []): void
    {
        $this->logger->log('emergency', (string)$message, $context);
    }

    public function alert($message, array $context = []): void
    {
        $this->logger->log('alert', (string)$message, $context);
    }

    public function critical($message, array $context = []): void
    {
        $this->logger->critical((string)$message, $context);
    }

    public function error($message, array $context = []): void
    {
        $this->logger->error((string)$message, $context);
    }

    public function warning($message, array $context = []): void
    {
        $this->logger->warning((string)$message, $context);
    }

    public function notice($message, array $context = []): void
    {
        $this->logger->log('notice', (string)$message, $context);
    }

    public function info($message, array $context = []): void
    {
        $this->logger->info((string)$message, $context);
    }

    public function debug($message, array $context = []): void
    {
        $this->logger->debug((string)$message, $context);
    }

    public function log($level, $message, array $context = []): void
    {
        $this->logger->log((string)$level, (string)$message, $context);
    }
}
