<?php

declare(strict_types=1);

namespace Proxbet\Core\Exceptions;

final class SecurityException extends ProxbetException
{
    /**
     * @param array<string,mixed> $details
     */
    public function __construct(
        string $message = 'Security policy violation',
        int $httpStatusCode = 403,
        array $details = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, false, $details, $previous, $httpStatusCode);
    }
}
