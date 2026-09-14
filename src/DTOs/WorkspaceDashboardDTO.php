<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\DTOs;

use JsonSerializable;

readonly class WorkspaceDashboardDTO implements JsonSerializable
{
    /**
     * @param  array<string, array{
     *     name: string,
     *     shortName: string,
     *     workspace: string,
     *     workspaceVendor: ?string,
     *     isFlat: bool,
     *     packagePath: string,
     *     alias: ?string,
     *     installStatus: 'require'|'require-dev'|'unlinked',
     *     gitBranch: ?string,
     *     isGitRepo: bool,
     *     isDirty: bool,
     *     dirtyFilesCount: int,
     *     upstreamStatus: string,
     *     auditStatus: 'PASSED'|'FAILED'|'MISSING',
     *     auditVersion: ?string,
     *     hasTests: bool,
     *     hasPhpstan: bool,
     *     skillsCount: int
     * }>  $packages
     */
    public function __construct(
        public array $packages,
        public int $totalPackages,
        public int $totalWorkspaces,
        public int $dirtyCount,
        public int $installedCount,
        public int $auditPassedCount,
        public int $auditFailedCount,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'summary' => [
                'total_packages' => $this->totalPackages,
                'total_workspaces' => $this->totalWorkspaces,
                'dirty_packages' => $this->dirtyCount,
                'installed_packages' => $this->installedCount,
                'audit_passed' => $this->auditPassedCount,
                'audit_failed' => $this->auditFailedCount,
            ],
            'packages' => array_values($this->packages),
        ];
    }
}
