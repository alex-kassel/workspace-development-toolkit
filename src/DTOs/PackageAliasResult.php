<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\DTOs;

readonly class PackageAliasResult
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
     * Convert to array representation.
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
}
