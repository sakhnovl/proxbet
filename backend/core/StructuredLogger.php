<?php

declare(strict_types=1);

namespace Proxbet\Core;

require_once __DIR__ . '/../security/LogFilter.php';

use Proxbet\Security\LogFilter;

/**
 * Structured JSON logger with correlation ID support
 * PSR-3 compatible logging with enhanced observability
 */
final class StructuredLogger
{
    private static ?self $instance = null;
    /** @var resource|null */
    private $stream = null;
    private ?string $correlationId = null;
    private string $serviceName;
    private string $environment;

    private function __construct()
    {
        $this->stream = fopen('php://stdout', 'wb');
        $this->serviceName = $_ENV['SERVICE_NAME'] ?? 'proxbet';
        $this->environment = $_ENV['APP_ENV'] ?? 'production';
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function setCorrelationId(string $correlationId): void
    {
        $this->correlationId = $correlationId;
    }

    public function getCorrelationId(): ?string
    {
        return $this->correlationId;
    }

    public function generateCorrelationId(): string
    {
        $this->correlationId = bin2hex(random_bytes(16));
        return $this->correlationId;
    }

    /** @param array<string,mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function error(string $message, array $context = [], ?\Throwable $exception = null): void
    {
        if ($exception !== null) {
            $context['exception'] = [
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $this->formatStackTrace($exception),
            ];
        }
        $this->log('ERROR', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function critical(string $message, array $context = [], ?\Throwable $exception = null): void
    {
        if ($exception !== null) {
            $context['exception'] = [
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $this->formatStackTrace($exception),
            ];
        }
        $this->log('CRITICAL', $message, $context);
    }

    /**
     * Log business metrics
     * @param array<string,mixed> $metrics
     */
    public function metric(string $metricName, array $metrics): void
    {
        $this->log('METRIC', $metricName, $metrics);
    }

    /** @param array<string,mixed> $context */
    private function log(string $level, string $message, array $context): void
    {
        if ($this->stream === null) {
            return;
        }

        // Filter sensitive data
        $filteredMessage = LogFilter::filter($message);
        $filteredContext = LogFilter::filterArray($context);

        $logEntry = [
            'timestamp' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),
            'level' => $level,
            'message' => $filteredMessage,
            'service' => $this->serviceName,
            'environment' => $this->environment,
            'correlation_id' => $this->correlationId,
            'pid' => getmypid(),
            'memory_usage' => memory_get_usage(true),
            'context' => $filteredContext,
        ];

        $json = json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            fwrite($this->stream, $json . "\n");
        }
    }

    /** @return array<int,string> */
    private function formatStackTrace(\Throwable $exception): array
    {
        $trace = [];
        foreach ($exception->getTrace() as $frame) {
            $file = $frame['file'] ?? 'unknown';
            $line = $frame['line'] ?? 0;
            $function = $frame['function'] ?? 'unknown';
            $class = $frame['class'] ?? '';
            $type = $frame['type'] ?? '';
            
            $trace[] = sprintf('%s%s%s() at %s:%d', $class, $type, $function, $file, $line);
        }
        return array_slice($trace, 0, 10); // Limit to 10 frames
    }

    public function __destruct()
    {
        if ($this->stream !== null) {
            fclose($this->stream);
        }
    }
}
