<?php

declare(strict_types=1);

namespace Proxbet\Core\Exceptions;

/**
 * Base exception for all Proxbet-specific exceptions.
 */
class ProxbetException extends \Exception
{
    /** @var array<string,mixed> */
    private array $details;
    private bool $userFriendly;
    private int $httpStatusCode;

    /**
     * @param array<string,mixed> $details
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        bool $userFriendly = false,
        array $details = [],
        ?\Throwable $previous = null,
        int $httpStatusCode = 500
    ) {
        parent::__construct($message, $code, $previous);

        $this->details = $details;
        $this->userFriendly = $userFriendly;
        $this->httpStatusCode = $httpStatusCode;
    }

    /**
     * @return array<string,mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    public function isUserFriendly(): bool
    {
        return $this->userFriendly;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }
}
