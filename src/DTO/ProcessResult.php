<?php

declare(strict_types=1);

namespace Siteko\FilamentResticBackups\DTO;

use DateTimeImmutable;

class ProcessResult
{
    /**
     * @param  array<int, string>  $command
     * @param  array<string, mixed> | null  $parsedJson
     */
    public function __construct(
        public int $exitCode,
        public int $durationMs,
        public string $stdout,
        public string $stderr,
        public ?array $parsedJson = null,
        public array $command = [],
        public ?DateTimeImmutable $startedAt = null,
        public ?DateTimeImmutable $finishedAt = null,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }

    public function safeCommandString(): string
    {
        if ($this->command === []) {
            return '';
        }

        return implode(' ', array_map($this->escapeArgument(...), $this->command));
    }

    private function escapeArgument(string $argument): string
    {
        if ($argument === '') {
            return "''";
        }

        if (preg_match('/\s|["\\\\]/', $argument) !== 1) {
            return $argument;
        }

        return '"' . addcslashes($argument, "\"\\") . '"';
    }
}
