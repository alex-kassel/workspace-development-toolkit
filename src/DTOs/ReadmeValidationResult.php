<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\DTOs;

use ArrayAccess;
use JsonSerializable;

/**
 * @implements ArrayAccess<string, mixed>
 */
final readonly class ReadmeValidationResult implements ArrayAccess, JsonSerializable
{
    /**
     * @param  array{passed: int, failed: int}  $summary
     * @param  array<string, array{name: string, status: string, message: string}>  $checks
     */
    public function __construct(
        public string $package,
        public string $path,
        public string $status,
        public array $summary,
        public array $checks,
    ) {}

    public function isPassed(): bool
    {
        return $this->status === 'passed';
    }

    public function failedCount(): int
    {
        return $this->summary['failed'] ?? 0;
    }

    public function passedCount(): int
    {
        return $this->summary['passed'] ?? 0;
    }

    /**
     * @return array{package: string, path: string, status: string, summary: array{passed: int, failed: int}, checks: array<string, array{name: string, status: string, message: string}>}
     */
    public function toArray(): array
    {
        return [
            'package' => $this->package,
            'path' => $this->path,
            'status' => $this->status,
            'summary' => $this->summary,
            'checks' => $this->checks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->toArray()[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->toArray()[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        // Immutable DTO
    }

    public function offsetUnset(mixed $offset): void
    {
        // Immutable DTO
    }
}
