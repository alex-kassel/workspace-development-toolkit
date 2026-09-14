<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\DTOs;

final readonly class PackageStatus
{
    public function __construct(
        public string $packageName,
        public string $workspace,
        public string $relativePath,
        public ?string $branch,
        public string $gitStatus,
        public string $upstream,
        public string $installStatus,
        public string $auditStatus,
    ) {}

    /**
     * Determine if package has uncommitted changes, unpushed commits, or git/audit drift.
     */
    public function isDirty(): bool
    {
        if ($this->gitStatus !== 'Clean' && $this->gitStatus !== 'No git repo') {
            return true;
        }

        if (str_starts_with($this->upstream, 'Ahead') || str_starts_with($this->upstream, 'Behind') || str_starts_with($this->upstream, 'Diverged')) {
            return true;
        }

        if (str_starts_with($this->auditStatus, 'Drift') || in_array($this->auditStatus, ['Tampered', 'Forged', 'Invalid'], true)) {
            return true;
        }

        return false;
    }

    /**
     * Determine if package has any warnings or dirty states requiring attention.
     */
    public function hasIssues(): bool
    {
        if ($this->isDirty()) {
            return true;
        }

        if (str_contains($this->installStatus, 'Corrupted')) {
            return true;
        }

        return false;
    }

    /**
     * Convert DTO to an array suitable for JSON serialization and tables.
     *
     * @return array{
     *     package: string,
     *     workspace: string,
     *     path: string,
     *     branch: ?string,
     *     git_status: string,
     *     upstream: string,
     *     installed: string,
     *     audit: string,
     *     has_issues: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'package' => $this->packageName,
            'workspace' => $this->workspace,
            'path' => $this->relativePath,
            'branch' => $this->branch,
            'git_status' => $this->gitStatus,
            'upstream' => $this->upstream,
            'installed' => $this->installStatus,
            'audit' => $this->auditStatus,
            'has_issues' => $this->hasIssues(),
        ];
    }
}
