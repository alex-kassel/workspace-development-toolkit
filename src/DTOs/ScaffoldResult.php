<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\DTOs;

use ArrayAccess;
use JsonSerializable;

/**
 * @implements ArrayAccess<string, mixed>
 */
final readonly class ScaffoldResult implements ArrayAccess, JsonSerializable
{
    public function __construct(
        public string $name,
        public string $package,
        public string $vendorName,
        public string $packageName,
        public string $shortName,
        public string $packagePath,
        public string $displayPath,
        public ?string $alias,
        public string $workspace,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'package' => $this->package,
            'vendorName' => $this->vendorName,
            'packageName' => $this->packageName,
            'shortName' => $this->shortName,
            'packagePath' => $this->packagePath,
            'displayPath' => $this->displayPath,
            'alias' => $this->alias,
            'workspace' => $this->workspace,
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
