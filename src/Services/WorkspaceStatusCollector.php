<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\PackageStatus;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\File;

class WorkspaceStatusCollector
{
    public function __construct(
        protected WorkspaceManager $workspaceManager,
        protected GitInspector $gitInspector,
        protected CertificateVerifier $certificateVerifier,
        protected FilesystemHelper $filesystemHelper,
    ) {}

    /**
     * Collect status across all packages in registered workspaces.
     *
     * @return array<int, PackageStatus>
     */
    public function collect(?string $workspaceFilter = null, bool $onlyDirty = false): array
    {
        $manifest = $this->workspaceManager->sync();
        $workspaces = $manifest['workspaces'];

        if ($workspaceFilter !== null && isset($workspaces[$workspaceFilter])) {
            $workspaces = [$workspaceFilter => $workspaces[$workspaceFilter]];
        } elseif ($workspaceFilter !== null) {
            return [];
        }

        $statuses = [];

        foreach ($workspaces as $workspaceName => $config) {
            $packages = $config['packages'];

            foreach ($packages as $pkg) {
                $rawName = is_array($pkg) ? $pkg['name'] : (string) $pkg;
                $canonicalName = $this->workspaceManager->resolveCanonicalPackageName($rawName, $workspaceName);
                $relPath = $this->workspaceManager->findPackagePath($canonicalName)
                    ?? $this->workspaceManager->findPackagePath($rawName);

                if ($relPath === null) {
                    $vendor = $config['vendor'] ?? null;
                    $relPath = $vendor ? "{$workspaceName}/{$rawName}" : "{$workspaceName}/{$rawName}";
                }

                $fullPath = base_path($relPath);

                if (! File::isDirectory($fullPath)) {
                    $status = new PackageStatus(
                        packageName: $canonicalName,
                        workspace: $workspaceName,
                        relativePath: $relPath,
                        branch: null,
                        gitStatus: 'Directory missing',
                        upstream: 'No git repo',
                        installStatus: 'Corrupted',
                        auditStatus: 'Uncertified',
                    );

                    if (! $onlyDirty || $status->isDirty()) {
                        $statuses[] = $status;
                    }

                    continue;
                }

                // 1. Git State
                $branch = $this->gitInspector->getCurrentBranch($fullPath);
                $gitStatus = $this->gitInspector->getWorkingTreeSummary($fullPath);
                $upstream = $this->gitInspector->getUpstreamStatus($fullPath);

                // 2. Composer / Install State
                $installStatus = $this->determineInstallStatus($canonicalName);

                // 3. Audit State
                $auditStatus = $this->determineAuditStatus($fullPath);

                $status = new PackageStatus(
                    packageName: $canonicalName,
                    workspace: $workspaceName,
                    relativePath: $relPath,
                    branch: $branch,
                    gitStatus: $gitStatus,
                    upstream: $upstream,
                    installStatus: $installStatus,
                    auditStatus: $auditStatus,
                );

                if (! $onlyDirty || $status->isDirty()) {
                    $statuses[] = $status;
                }
            }
        }

        return $statuses;
    }

    /**
     * Determine installation and symlink state for a canonical package name.
     */
    protected function determineInstallStatus(string $canonicalName): string
    {
        $isInstalled = class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($canonicalName);
        $vendorPath = base_path("vendor/{$canonicalName}");
        $isSymlinked = $this->filesystemHelper->isLinkOrJunction($vendorPath);

        if (! $isInstalled && ! $isSymlinked) {
            return 'Not installed';
        }

        $version = null;
        if ($isInstalled) {
            try {
                $version = InstalledVersions::getPrettyVersion($canonicalName);
            } catch (\Throwable) {
                $version = null;
            }
        }

        if ($isSymlinked) {
            return $version ? "Symlinked ({$version})" : 'Symlinked';
        }

        return $version ? "Installed ({$version})" : 'Installed';
    }

    /**
     * Determine audit certificate state.
     */
    protected function determineAuditStatus(string $fullPath): string
    {
        $certificatePath = $fullPath.DIRECTORY_SEPARATOR.'AUDIT.json';
        if (! File::exists($certificatePath)) {
            return 'Uncertified';
        }

        try {
            $verification = $this->certificateVerifier->verify($fullPath);

            return match ($verification->status) {
                'VERIFIED' => 'Verified',
                'DRIFT' => 'Drift',
                'TAMPERED' => 'Tampered',
                'FORGED' => 'Forged',
                'INVALID' => 'Invalid',
                'MISSING' => 'Uncertified',
                default => ucfirst(strtolower($verification->status)),
            };
        } catch (\Throwable) {
            return 'Invalid';
        }
    }
}
