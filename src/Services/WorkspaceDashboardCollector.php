<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\WorkspaceDashboardDTO;
use Illuminate\Support\Facades\File;

class WorkspaceDashboardCollector
{
    public function __construct(
        protected ManifestRepository $manifest,
        protected PackageResolver $resolver,
        protected GitInspector $gitInspector,
        protected ComposerManager $composer,
    ) {}

    /**
     * Collect real-time dashboard data across all registered workspaces.
     */
    public function collect(?string $workspaceFilter = null, bool $onlyDirty = false): WorkspaceDashboardDTO
    {
        $allWorkspaces = $this->manifest->all();
        $rootComposer = $this->readRootComposer();

        $rootRequire = array_keys($rootComposer['require'] ?? []);
        $rootRequireDev = array_keys($rootComposer['require-dev'] ?? []);

        $packages = [];
        $totalWorkspaces = count($allWorkspaces);
        $dirtyCount = 0;
        $installedCount = 0;
        $auditPassedCount = 0;
        $auditFailedCount = 0;

        foreach ($allWorkspaces as $wsPath => $config) {
            if ($workspaceFilter !== null && $wsPath !== $workspaceFilter) {
                continue;
            }

            $vendor = $config['vendor'] ?? null;
            $isFlat = $vendor !== null;
            $packageEntries = $config['packages'] ?? [];

            foreach ($packageEntries as $entry) {
                $rawName = is_array($entry) ? ($entry['name'] ?? '') : (string) $entry;
                $alias = is_array($entry) ? ($entry['alias'] ?? null) : null;

                if ($rawName === '') {
                    continue;
                }

                // Canonical composer package name
                $canonicalName = $isFlat && ! str_contains($rawName, '/')
                    ? "{$vendor}/{$rawName}"
                    : $rawName;

                $shortName = str_contains($rawName, '/')
                    ? explode('/', $rawName, 2)[1]
                    : $rawName;

                $relPath = $this->resolver->findPackagePath($canonicalName, $wsPath);
                if ($relPath === null) {
                    continue;
                }

                $fullPath = base_path($relPath);
                if (! File::isDirectory($fullPath)) {
                    continue;
                }

                // 1. Installation status
                if (in_array($canonicalName, $rootRequireDev, true)) {
                    $installStatus = 'require-dev';
                    $installedCount++;
                } elseif (in_array($canonicalName, $rootRequire, true)) {
                    $installStatus = 'require';
                    $installedCount++;
                } else {
                    $installStatus = 'unlinked';
                }

                // 2. Git status
                $isGitRepo = $this->gitInspector->hasGitRepository($fullPath);
                $gitBranch = $isGitRepo ? $this->gitInspector->getCurrentBranch($fullPath) : null;
                $isDirty = false;
                $dirtyFilesCount = 0;
                $upstreamStatus = 'No git repo';

                if ($isGitRepo) {
                    $dirtyFilesCount = $this->gitInspector->getDirtyFilesCount($fullPath);
                    $isDirty = $dirtyFilesCount > 0;
                    if ($isDirty) {
                        $dirtyCount++;
                    }

                    $upstreamStatus = $this->gitInspector->getUpstreamStatus($fullPath);
                }

                if ($onlyDirty && ! $isDirty) {
                    continue;
                }

                // 3. Audit Certificate status
                $auditStatus = 'MISSING';
                $auditVersion = null;
                $auditPath = $fullPath.DIRECTORY_SEPARATOR.'AUDIT.json';

                if (File::exists($auditPath)) {
                    try {
                        $auditData = json_decode((string) File::get($auditPath), true);
                        if (is_array($auditData)) {
                            $auditStatus = ($auditData['verdict'] ?? 'FAILED') === 'PASSED' ? 'PASSED' : 'FAILED';
                            $auditVersion = $auditData['version'] ?? null;
                            if ($auditStatus === 'PASSED') {
                                $auditPassedCount++;
                            } else {
                                $auditFailedCount++;
                            }
                        }
                    } catch (\Throwable) {
                        $auditStatus = 'FAILED';
                        $auditFailedCount++;
                    }
                }

                // 4. Feature indicators
                $hasTests = File::isDirectory($fullPath.DIRECTORY_SEPARATOR.'tests');
                $hasPhpstan = File::exists($fullPath.DIRECTORY_SEPARATOR.'phpstan.neon')
                    || File::exists($fullPath.DIRECTORY_SEPARATOR.'phpstan.neon.dist');

                $skillsDir = $fullPath.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'skills';
                $skillsCount = File::isDirectory($skillsDir)
                    ? count(File::directories($skillsDir))
                    : 0;

                $packages[$canonicalName] = [
                    'name' => $canonicalName,
                    'shortName' => $shortName,
                    'workspace' => $wsPath,
                    'workspaceVendor' => $vendor,
                    'isFlat' => $isFlat,
                    'packagePath' => $relPath,
                    'alias' => $alias,
                    'installStatus' => $installStatus,
                    'gitBranch' => $gitBranch,
                    'isGitRepo' => $isGitRepo,
                    'isDirty' => $isDirty,
                    'dirtyFilesCount' => $dirtyFilesCount,
                    'upstreamStatus' => $upstreamStatus,
                    'auditStatus' => $auditStatus,
                    'auditVersion' => $auditVersion,
                    'hasTests' => $hasTests,
                    'hasPhpstan' => $hasPhpstan,
                    'skillsCount' => $skillsCount,
                ];

            }
        }

        return new WorkspaceDashboardDTO(
            packages: $packages,
            totalPackages: count($packages),
            totalWorkspaces: $totalWorkspaces,
            dirtyCount: $dirtyCount,
            installedCount: $installedCount,
            auditPassedCount: $auditPassedCount,
            auditFailedCount: $auditFailedCount,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function readRootComposer(): array
    {
        $path = base_path('composer.json');
        if (! File::exists($path)) {
            return [];
        }

        try {
            $data = json_decode((string) File::get($path), true);

            return is_array($data) ? $data : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
