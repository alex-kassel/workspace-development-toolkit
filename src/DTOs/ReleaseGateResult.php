<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\DTOs;

use ArrayAccess;
use JsonSerializable;

/**
 * @implements ArrayAccess<string, mixed>
 */
final readonly class ReleaseGateResult implements ArrayAccess, JsonSerializable
{
    public const int EXIT_READY = 0;

    public const int EXIT_BLOCKED = 1;

    public const int EXIT_ACTION_REQUIRED = 2;

    /**
     * @param  array<string, array{name: string, status: string, message: string}>  $checks
     */
    public function __construct(
        public string $package,
        public string $path,
        public string $verdict,
        public string $latestTag,
        public array $checks,
    ) {}

    public function isReady(): bool
    {
        return $this->verdict === 'READY';
    }

    public function isBlocked(): bool
    {
        return $this->verdict === 'BLOCKED';
    }

    public function isActionRequired(): bool
    {
        return $this->verdict === 'ACTION_REQUIRED';
    }

    public function exitCode(): int
    {
        return match ($this->verdict) {
            'READY' => self::EXIT_READY,
            'ACTION_REQUIRED' => self::EXIT_ACTION_REQUIRED,
            default => self::EXIT_BLOCKED,
        };
    }

    /**
     * @return array{package: string, path: string, verdict: string, latest_tag: string, checks: array<string, array{name: string, status: string, message: string}>}
     */
    public function toArray(): array
    {
        return [
            'package' => $this->package,
            'path' => $this->path,
            'verdict' => $this->verdict,
            'latest_tag' => $this->latestTag,
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
        if ($offset === 'latestTag') {
            return true;
        }

        return isset($this->toArray()[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        if ($offset === 'latestTag') {
            return $this->latestTag;
        }

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
