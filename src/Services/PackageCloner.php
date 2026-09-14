<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\GitDiagnosticResult;
use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class PackageCloner
{
    public function __construct(
        protected WorkspaceManager $workspace,
        protected ComposerManager $composer,
        protected GitDiagnosticService $diagnostics,
    ) {}

    /**
     * Clone a git repository into the specified directory.
     * Returns null on success, or GitDiagnosticResult on failure.
     */
    public function cloneRepository(string $repoUrl, string $targetFullPath, int $timeout = 300): ?GitDiagnosticResult
    {
        if (str_contains($repoUrl, "\0") || str_contains($targetFullPath, "\0")) {
            return new GitDiagnosticResult(
                type: 'invalid_characters',
                title: 'Invalid Characters in Repository URL or Target Path',
                explanation: 'The specified repository URL or target path contains forbidden null bytes.',
                actionableSteps: ['Verify the package name or URL and remove any control characters.']
            );
        }

        if (strlen($targetFullPath) > 1024 || strlen(basename($targetFullPath)) > 255) {
            return new GitDiagnosticResult(
                type: 'path_too_long',
                title: 'Path Exceeds Filesystem Limits',
                explanation: 'The target directory path exceeds maximum filesystem length limits.',
                actionableSteps: ['Specify a shorter package name or directory alias.']
            );
        }

        try {
            File::ensureDirectoryExists(dirname($targetFullPath));
        } catch (\Throwable $e) {
            return new GitDiagnosticResult(
                type: 'directory_creation_failed',
                title: 'Directory Creation Failed',
                explanation: "Could not create target directory: {$e->getMessage()}",
                actionableSteps: ['Check directory permissions and verify the target path is valid.']
            );
        }

        try {
            $cloneResult = Process::timeout($timeout)->run(['git', 'clone', '--', $repoUrl, $targetFullPath]);
        } catch (\Throwable $e) {
            return new GitDiagnosticResult(
                type: 'process_execution_failed',
                title: 'Git Clone Process Failed',
                explanation: "Process execution failed: {$e->getMessage()}",
                actionableSteps: ['Verify that Git is installed and that the parameters are valid.']
            );
        }

        if ($cloneResult->successful()) {
            return null;
        }

        $gitOutput = trim($cloneResult->errorOutput() ?: $cloneResult->output());
        $diagnostic = $this->diagnostics->diagnoseCloneFailure($gitOutput, $repoUrl);

        if (File::isDirectory($targetFullPath)) {
            File::deleteDirectory($targetFullPath);
        }

        return $diagnostic;
    }

    /**
     * Recursively clone dependencies from trusted organizations.
     *
     * @param  array<string, bool>  $visited
     */
    public function cloneDependenciesRecursively(
        string $composerPath,
        string $workspace,
        bool $useSsh,
        bool $install,
        bool $dev,
        int $timeout,
        array &$visited,
        ?string $rootVendor = null,
        ?Closure $onMessage = null
    ): void {
        $trustedOrgs = (array) config('workspace.trusted_organizations', []);
        if ($rootVendor !== null && ! in_array($rootVendor, $trustedOrgs, true)) {
            $trustedOrgs[] = $rootVendor;
        }

        if (empty($trustedOrgs) || ! File::exists($composerPath)) {
            return;
        }

        $content = json_decode(File::get($composerPath), true);
        if (! is_array($content)) {
            return;
        }

        $dependencies = array_merge(
            array_keys($content['require'] ?? []),
            array_keys($content['require-dev'] ?? [])
        );

        $workspaceVendor = $this->workspace->getWorkspaceVendor($workspace);

        foreach ($dependencies as $dep) {
            if (! is_string($dep) || ! str_contains($dep, '/')) {
                continue;
            }

            [$depVendor, $depPackage] = explode('/', $dep, 2);

            if (! in_array($depVendor, $trustedOrgs, true)) {
                continue;
            }

            if (isset($visited[$dep])) {
                continue;
            }

            $visited[$dep] = true;

            if ($workspaceVendor !== null) {
                if (strtolower($depVendor) !== strtolower($workspaceVendor)) {
                    $this->emitMessage($onMessage, 'warn', "Skipping recursive dependency [{$dep}]: vendor [{$depVendor}] does not match fixed workspace vendor [{$workspaceVendor}].");

                    continue;
                }
                $relTarget = "{$workspace}/{$depPackage}";
            } else {
                $relTarget = "{$workspace}/{$depVendor}/{$depPackage}";
            }

            $fullTarget = base_path($relTarget);
            if (File::exists($fullTarget)) {
                $this->emitMessage($onMessage, 'line', "Dependency [{$dep}] already exists at [{$relTarget}], inspecting nested dependencies...");
                $depComposerPath = "{$fullTarget}/composer.json";
                if (File::exists($depComposerPath)) {
                    $this->cloneDependenciesRecursively($depComposerPath, $workspace, $useSsh, $install, $dev, $timeout, $visited, $rootVendor, $onMessage);
                }

                continue;
            }

            $depRepoUrl = $this->workspace->normalizeRepositoryUrl($dep, $useSsh);
            $this->emitMessage($onMessage, 'info', "Recursively cloning dependency [{$dep}] into [{$relTarget}]...");

            $diagnostic = $this->cloneRepository($depRepoUrl, $fullTarget, $timeout);

            if ($diagnostic !== null) {
                $this->emitMessage($onMessage, 'warn', "Failed to clone dependency [{$dep}] from [{$depRepoUrl}].");
                $this->emitMessage($onMessage, 'line', "  <comment>[{$diagnostic->title}]</comment> {$diagnostic->explanation}");
                if (! empty($diagnostic->actionableSteps)) {
                    $this->emitMessage($onMessage, 'line', "  • Hint: {$diagnostic->actionableSteps[0]}");
                }

                continue;
            }

            // Sync and record
            $this->workspace->sync();
            $recorded = $workspaceVendor !== null ? $depPackage : $dep;
            $this->workspace->recordPackage($workspace, $recorded, null, null);

            $depComposerPath = "{$fullTarget}/composer.json";
            if ($install) {
                $this->emitMessage($onMessage, 'info', "Registering and symlinking dependency [{$dep}] into root application...");
                $requireArgs = ['require', "{$dep}:@dev"];
                if ($dev) {
                    $requireArgs[] = '--dev';
                }
                try {
                    $this->composer->runComposer($requireArgs, $timeout);
                } catch (\Throwable $e) {
                    $this->emitMessage($onMessage, 'warn', "Failed to install dependency [{$dep}]: {$e->getMessage()}");
                }
            }

            if (File::exists($depComposerPath)) {
                $this->cloneDependenciesRecursively($depComposerPath, $workspace, $useSsh, $install, $dev, $timeout, $visited, $rootVendor, $onMessage);
            }
        }
    }

    /**
     * Link the host application's AGENTS.md to the local cloned toolkit's stub.
     *
     * @param  string  $packageRelativePath  Relative path to the cloned package (e.g. packages/alex-kassel/workspace-development-toolkit)
     * @return bool True if successfully linked, false if stub was not found or link failed.
     */
    public function linkHostAgentsGuideline(string $packageRelativePath): bool
    {
        $context = new \AlexKassel\WorkspaceDevelopmentToolkit\DTOs\InstallContext(
            rootPath: base_path(),
            isSelf: true,
            selfPackagePath: $packageRelativePath,
        );

        return app(\AlexKassel\WorkspaceDevelopmentToolkit\Actions\SetupAgentsGuidelineAction::class)->execute($context);
    }

    protected function emitMessage(?Closure $onMessage, string $level, string $message): void
    {
        if ($onMessage !== null) {
            $onMessage($level, $message);
        }
    }
}
