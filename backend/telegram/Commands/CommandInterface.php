<?php

declare(strict_types=1);

namespace Proxbet\Telegram\Commands;

/**
 * Interface for Telegram bot commands
 */
interface CommandInterface
{
    /**
     * Execute the command
     *
     * @param array<string, mixed> $context Command execution context
     * @return bool True if command was handled
     */
    public function execute(array $context): bool;

    /**
     * Get command name (e.g., '/start', '/balance')
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Check if this command can handle the given input
     *
     * @param string $input User input
     * @return bool
     */
    public function canHandle(string $input): bool;
}
