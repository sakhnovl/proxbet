<?php

declare(strict_types=1);

namespace Proxbet\Core\Exceptions;

final class NotFoundException extends ProxbetException
{
    /**
     * @param array<string,mixed> $details
     */
    public function __construct(
        string $message = 'Resource not found',
        array $details = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 404, true, $details, $previous);
    }
}
