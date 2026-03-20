<?php

declare(strict_types=1);

namespace Proxbet\Statistic;

final readonly class ValidationResult
{
    /**
     * @param bool $valid
     * @param array<int,string> $errors
     * @param array<int,string> $warnings
     */
    public function __construct(
        public bool $valid,
        public array $errors = [],
        public array $warnings = [],
    ) {
    }

    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }

    public function hasWarnings(): bool
    {
        return count($this->warnings) > 0;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
        ];
    }
}
