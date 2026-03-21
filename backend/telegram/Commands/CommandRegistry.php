<?php

declare(strict_types=1);

namespace Proxbet\Telegram\Commands;

/**
 * Registry for Telegram bot commands
 */
class CommandRegistry
{
    /** @var array<string, CommandInterface> */
    private array $commands = [];

    /**
     * Register a command
     */
    public function register(CommandInterface $command): void
    {
        $this->commands[$command->getName()] = $command;
    }

    /**
     * Find command that can handle the input
     */
    public function findCommand(string $input): ?CommandInterface
    {
        foreach ($this->commands as $command) {
            if ($command->canHandle($input)) {
                return $command;
            }
        }

        return null;
    }

    /**
     * Get all registered commands
     *
     * @return array<string, CommandInterface>
     */
    public function getAll(): array
    {
        return $this->commands;
    }
}
