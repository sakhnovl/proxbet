<?php

declare(strict_types=1);

namespace Proxbet\Core;

use Proxbet\Core\Exceptions\ProxbetException;

/**
 * Unified error response DTO.
 * Provides consistent error response structure across the application.
 */
final class ErrorResponse
{
    /**
     * @param string $message User-friendly error message
     * @param int $code Error code
     * @param string|null $type Error type/category
     * @param array<string,mixed>|null $details Additional error details
     * @param array<string,mixed>|null $debug Debug information (only in development)
     */
    public function __construct(
        private string $message,
        private int $code = 0,
        private ?string $type = null,
        private ?array $details = null,
        private ?array $debug = null
    ) {
    }

    /**
     * Create ErrorResponse from exception.
     * 
     * @param \Throwable $exception The exception
     * @param bool $includeDebug Whether to include debug information
     * @return self
     */
    public static function fromException(\Throwable $exception, bool $includeDebug = false): self
    {
        $message = $exception->getMessage();
        $code = $exception->getCode();
        $type = self::getExceptionType($exception);
        $details = null;
        $debug = null;

        // Get details from ProxbetException
        if ($exception instanceof ProxbetException) {
            $details = $exception->getDetails();
            
            // Sanitize message for production
            if (!$includeDebug && !$exception->isUserFriendly()) {
                $message = 'An error occurred. Please try again later.';
            }
        } else {
            // Generic exception - hide details in production
            if (!$includeDebug) {
                $message = 'An error occurred. Please try again later.';
            }
        }

        // Add debug information in development
        if ($includeDebug) {
            $debug = [
                'exception' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => array_slice($exception->getTrace(), 0, 5), // Limit trace depth
            ];
        }

        return new self($message, $code, $type, $details, $debug);
    }

    /**
     * Create ErrorResponse for validation errors.
     * 
     * @param string $message Error message
     * @param array<string,mixed> $errors Validation errors
     * @return self
     */
    public static function validation(string $message, array $errors): self
    {
        return new self(
            $message,
            400,
            'validation_error',
            ['errors' => $errors]
        );
    }

    /**
     * Create ErrorResponse for not found errors.
     * 
     * @param string $resource Resource name
     * @return self
     */
    public static function notFound(string $resource = 'Resource'): self
    {
        return new self(
            "{$resource} not found",
            404,
            'not_found'
        );
    }

    /**
     * Create ErrorResponse for unauthorized errors.
     * 
     * @param string $message Error message
     * @return self
     */
    public static function unauthorized(string $message = 'Unauthorized'): self
    {
        return new self(
            $message,
            401,
            'unauthorized'
        );
    }

    /**
     * Create ErrorResponse for forbidden errors.
     * 
     * @param string $message Error message
     * @return self
     */
    public static function forbidden(string $message = 'Forbidden'): self
    {
        return new self(
            $message,
            403,
            'forbidden'
        );
    }

    /**
     * Convert to array for JSON serialization.
     * 
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $response = [
            'error' => true,
            'message' => $this->message,
            'code' => $this->code,
        ];

        if ($this->type !== null) {
            $response['type'] = $this->type;
        }

        if ($this->details !== null) {
            $response['details'] = $this->details;
        }

        if ($this->debug !== null) {
            $response['debug'] = $this->debug;
        }

        return $response;
    }

    /**
     * Get exception type from exception class.
     * 
     * @param \Throwable $exception The exception
     * @return string Exception type
     */
    private static function getExceptionType(\Throwable $exception): string
    {
        $class = get_class($exception);
        $parts = explode('\\', $class);
        $name = end($parts);
        
        // Convert CamelCase to snake_case
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }
}
