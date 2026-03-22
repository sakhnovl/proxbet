<?php

declare(strict_types=1);

namespace Proxbet\Core\Exceptions;

/**
 * Exception thrown when input validation fails.
 */
class ValidationException extends ProxbetException
{
    /**
     * @param array<string,mixed> $details
     */
    public function __construct(
        string $message = 'Validation failed',
        array $details = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, true, $details, $previous, 400);
    }
}
