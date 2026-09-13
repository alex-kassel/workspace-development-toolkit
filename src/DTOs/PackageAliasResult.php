<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\DTOs;

use ArrayAccess;

/**
 * @implements ArrayAccess<string, mixed>
 */
readonly class PackageAliasResult implements ArrayAccess
{
    /**
     * @param  string  $oldPath  Old relative path on disk (e.g. "app/Cores/scraper-core")
     * @param  string  $newPath  New relative path on disk (e.g. "app/Cores/Scraper")
     * @param  string  $canonicalName  Canonical composer package name (e.g. "alex-kassel/scraper-core")
     * @param  string  $alias  Assigned directory alias (e.g. "Scraper")
     * @param  string  $workspace  Workspace relative path (e.g. "app/Cores")
     * @param  bool  $wasInstalled  Whether the package was installed/symlinked in vendor/
     */
    public function __construct(
        public string $oldPath,
        public string $newPath,
        public string $canonicalName,
        public string $alias,
        public string $workspace,
        public bool $wasInstalled = false,
    ) {}

    /**
     * Convert to array for backward compatibility.
     *
     * @return array{old_path: string, new_path: string, canonical_name: string, alias: string, workspace: string, was_installed: bool}
     */
    public function toArray(): array
    {
        return [
            'old_path' => $this->oldPath,
            'new_path' => $this->newPath,
            'canonical_name' => $this->canonicalName,
            'alias' => $this->alias,
            'workspace' => $this->workspace,
            'was_installed' => $this->wasInstalled,
        ];
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists((string) $offset, $this->toArray());
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->toArray()[(string) $offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \BadMethodCallException('PackageAliasResult is an immutable DTO.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \BadMethodCallException('PackageAliasResult is an immutable DTO.');
    }
}
