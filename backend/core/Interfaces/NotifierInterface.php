<?php

declare(strict_types=1);

namespace Proxbet\Core\Interfaces;

/**
 * Interface for notification services.
 */
interface NotifierInterface
{
    /**
     * Send notification.
     *
     * @param string $message
     * @param array<string,mixed> $context
     * @return bool
     */
    public function notify(string $message, array $context = []): bool;
}
