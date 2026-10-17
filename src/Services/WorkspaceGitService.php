<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\GitOperationResult;
use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\GitRepositoryState;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class WorkspaceGitService
{
    public function __construct(
        protected WorkspaceManager $workspace,
        protected GitInspector $inspector,
    ) {}

    public function hostPath(): string
    {
        return base_path();
    }

    public function hostName(): string
    {
        return basename(base_path());
    }

    /**
     * Inspect the host application repository.
     */
    public function hostState(bool $fetch = false): GitRepositoryState
    {
        return $this->stateFor(
            name: $this->hostName(),
            fullPath: $this->hostPath(),
            relativePath: '.',
            isHost: true,
            fetch: $fetch,
        );
    }

    /**
     * Collect git synchronization state for the host application and all workspace packages.
     *
     * @return array<int, GitRepositoryState>
     */
    public function collect(?string $workspaceFilter = null, bool $includeHost = true, bool $fetch = false): array
    {
        $manifest = $this->workspace->load();
        $workspaces = $manifest['workspaces'];

        if ($workspaceFilter !== null) {
            if (! isset($workspaces[$workspaceFilter])) {
                return [];
            }

            $workspaces = [$workspaceFilter => $workspaces[$workspaceFilter]];
        }

        $states = [];

        if ($includeHost) {
            $states[] = $this->hostState($fetch);
        }

        foreach ($workspaces as $workspaceName => $config) {
            /** @var array<int, string|array{name?: string, alias?: string, url?: string}> $packages */
            $packages = $config['packages'];

            foreach ($packages as $package) {
                $rawName = is_array($package) ? (string) ($package['name'] ?? '') : (string) $package;
                if ($rawName === '') {
                    continue;
                }

                $canonicalName = $this->workspace->resolveCanonicalPackageName($rawName, $workspaceName);
                $relativePath = $this->workspace->findPackagePath($canonicalName)
                    ?? $this->workspace->findPackagePath($rawName)
                    ?? $this->fallbackRelativePath($workspaceName, $rawName, $package);

                $states[] = $this->stateFor(
                    name: $canonicalName,
                    fullPath: base_path($relativePath),
                    relativePath: $relativePath,
                    isHost: false,
                    fetch: $fetch,
                );
            }
        }

        return $states;
    }

    /**
     * Clone any packages recorded in workspace.json that are missing on disk.
     */
    public function restoreMissing(): GitOperationResult
    {
        $runner = base_path('workspace');

        if (! File::exists($runner)) {
            return new GitOperationResult(
                repository: 'workspace',
                action: 'restore',
                success: false,
                message: 'Standalone workspace runner was not found. Run: php artisan workspace:sync',
            );
        }

        $result = Process::timeout((int) config('workspace.process_timeout', 300))
            ->path(base_path())
            ->run([PHP_BINARY, $runner, 'restore']);

        $output = trim($result->output());
        $restored = preg_match('/Restored:\s*(\d+)/', $output, $matches) === 1 ? (int) $matches[1] : 0;

        return new GitOperationResult(
            repository: 'workspace',
            action: 'restore',
            success: $result->successful(),
            message: $result->successful()
                ? ($restored > 0 ? "Restored {$restored} missing package(s)." : 'All workspace packages already exist.')
                : 'Failed to restore missing workspace packages.',
            output: $output,
            changed: $restored > 0,
        );
    }

    /**
     * Push local commits and tags of a repository.
     */
    public function push(GitRepositoryState $state, bool $followTags = true, bool $dryRun = false): GitOperationResult
    {
        $blocked = $this->pushBlockedReason($state);

        if ($blocked !== null) {
            return new GitOperationResult(
                repository: $state->name,
                action: 'push',
                success: false,
                message: $blocked,
            );
        }

        if (! $state->canPush()) {
            if ($state->syncStatus === 'Behind') {
                return new GitOperationResult(
                    repository: $state->name,
                    action: 'push',
                    success: true,
                    message: "Behind upstream by {$state->behind} commit(s); nothing to push (run workspace:pull)",
                );
            }

            return new GitOperationResult(
                repository: $state->name,
                action: 'push',
                success: true,
                message: 'Already up to date',
            );
        }

        if ($dryRun) {
            return new GitOperationResult(
                repository: $state->name,
                action: 'push',
                success: true,
                message: $state->syncStatus === 'Unpublished'
                    ? 'Would push new branch and set upstream'
                    : "Would push {$state->ahead} commit(s)",
                changed: true,
            );
        }

        $args = ['git', 'push'];

        if ($followTags) {
            $args[] = '--follow-tags';
        }

        if ($state->syncStatus === 'Unpublished') {
            $args[] = '--set-upstream';
        }

        $args[] = 'origin';
        $args[] = (string) $state->branch;

        $result = Process::timeout((int) config('workspace.process_timeout', 300))
            ->path($state->path)
            ->run($args);

        if (! $result->successful()) {
            return new GitOperationResult(
                repository: $state->name,
                action: 'push',
                success: false,
                message: $this->firstLine($result->errorOutput() ?: $result->output()),
                output: trim($result->output()."\n".$result->errorOutput()),
            );
        }

        $failedTags = $this->pushRemainingTags($state);

        $message = $state->syncStatus === 'Unpublished'
            ? 'Pushed new branch and set upstream'
            : "Pushed {$state->ahead} commit(s)";

        if ($failedTags !== []) {
            return new GitOperationResult(
                repository: $state->name,
                action: 'push',
                success: false,
                message: $message.', but failed to push tags: '.implode(', ', $failedTags),
                output: trim($result->output()),
                changed: true,
            );
        }

        return new GitOperationResult(
            repository: $state->name,
            action: 'push',
            success: true,
            message: $message,
            output: trim($result->output()),
            changed: true,
        );
    }

    /**
     * Fast-forward a repository to its upstream branch.
     */
    public function pull(GitRepositoryState $state, bool $dryRun = false): GitOperationResult
    {
        if (! $state->exists) {
            return new GitOperationResult($state->name, 'pull', false, 'Package directory is missing');
        }

        if (! $state->isRepository) {
            return new GitOperationResult($state->name, 'pull', false, 'Not a git repository');
        }

        if ($state->isDetached) {
            return new GitOperationResult($state->name, 'pull', false, 'HEAD is detached from any branch');
        }

        if (! $state->isClean) {
            return new GitOperationResult($state->name, 'pull', false, 'Working tree is dirty; commit or stash changes first');
        }

        if ($state->upstream === null) {
            return new GitOperationResult($state->name, 'pull', false, 'Branch has no upstream to pull from');
        }

        if (in_array($state->syncStatus, ['Ahead', 'Diverged', 'Unpublished'], true)) {
            return new GitOperationResult(
                $state->name,
                'pull',
                false,
                $state->syncStatus === 'Diverged'
                    ? 'Branch has diverged from upstream; resolve manually'
                    : 'Local branch has commits that are not on the remote; push them first',
            );
        }

        if ($state->syncStatus !== 'Behind') {
            return new GitOperationResult($state->name, 'pull', true, 'Already up to date');
        }

        if ($dryRun) {
            return new GitOperationResult(
                $state->name,
                'pull',
                true,
                "Would fast-forward {$state->behind} commit(s)",
                changed: true,
            );
        }

        $result = Process::timeout((int) config('workspace.process_timeout', 300))
            ->path($state->path)
            ->run(['git', 'pull', '--ff-only']);

        if (! $result->successful()) {
            return new GitOperationResult(
                $state->name,
                'pull',
                false,
                $this->firstLine($result->errorOutput() ?: $result->output()),
                output: trim($result->output()."\n".$result->errorOutput()),
            );
        }

        return new GitOperationResult(
            $state->name,
            'pull',
            true,
            "Fast-forwarded {$state->behind} commit(s)",
            output: trim($result->output()),
            changed: true,
        );
    }

    /**
     * Explain why a push cannot be performed, or return null when it can.
     */
    protected function pushBlockedReason(GitRepositoryState $state): ?string
    {
        if (! $state->exists) {
            return 'Package directory is missing';
        }

        if (! $state->isRepository) {
            return 'Not a git repository';
        }

        if ($state->isDetached) {
            return 'HEAD is detached from any branch';
        }

        if (! $state->isClean) {
            return 'Working tree is dirty; commit or stash changes first';
        }

        if ($state->originUrl === null) {
            return 'No "origin" remote configured';
        }

        if ($state->syncStatus === 'Diverged') {
            return 'Branch has diverged from upstream; resolve manually';
        }

        if ($state->syncStatus === 'No upstream') {
            return 'Branch has no commits to push';
        }

        return null;
    }

    /**
     * Push tags that were not included by `--follow-tags`.
     *
     * @return array<int, string> Tags that failed to push.
     */
    protected function pushRemainingTags(GitRepositoryState $state): array
    {
        $failed = [];

        foreach ($this->inspector->getUnpushedTags($state->path) as $tag) {
            $result = Process::timeout((int) config('workspace.process_timeout', 300))
                ->path($state->path)
                ->run(['git', 'push', 'origin', $tag]);

            if (! $result->successful()) {
                $failed[] = $tag;
            }
        }

        return $failed;
    }

    protected function stateFor(string $name, string $fullPath, string $relativePath, bool $isHost, bool $fetch): GitRepositoryState
    {
        if (! File::isDirectory($fullPath)) {
            return new GitRepositoryState(
                name: $name,
                path: $fullPath,
                relativePath: $relativePath,
                isHost: $isHost,
                exists: false,
                syncStatus: 'Missing',
            );
        }

        if (! $this->inspector->hasGitRepository($fullPath)) {
            return new GitRepositoryState(
                name: $name,
                path: $fullPath,
                relativePath: $relativePath,
                isHost: $isHost,
                exists: true,
                isRepository: false,
                syncStatus: 'No git repo',
            );
        }

        if ($fetch) {
            $this->inspector->fetch($fullPath);
        }

        $branch = $this->inspector->getCurrentBranch($fullPath);
        $isDetached = $this->inspector->isDetachedHead($fullPath);
        $dirtyFiles = $this->inspector->getDirtyFiles($fullPath);
        $dirtyFilesCount = count($dirtyFiles);
        $tracking = $this->inspector->getTrackingDetails($fullPath);
        $originUrl = $this->inspector->getRemoteUrl($fullPath);

        return new GitRepositoryState(
            name: $name,
            path: $fullPath,
            relativePath: $relativePath,
            isHost: $isHost,
            exists: true,
            isRepository: true,
            branch: $branch,
            isDetached: $isDetached,
            isClean: $dirtyFilesCount === 0,
            dirtyFilesCount: $dirtyFilesCount,
            dirtyFiles: $dirtyFiles,
            upstream: $tracking['upstream'],
            syncStatus: $tracking['status'],
            ahead: $tracking['ahead'],
            behind: $tracking['behind'],
            stashesCount: $this->inspector->getStashesCount($fullPath),
            unpushedTags: $this->inspector->getUnpushedTags($fullPath),
            originUrl: $originUrl,
        );
    }

    /**
     * Resolve a fallback relative path for packages that are not present on disk yet.
     *
     * @param  string|array{name?: string, alias?: string, url?: string}  $package
     */
    protected function fallbackRelativePath(string $workspaceName, string $rawName, string|array $package): string
    {
        $alias = is_array($package) ? ($package['alias'] ?? null) : null;
        $effectiveDir = is_string($alias) && $alias !== '' ? $alias : $rawName;

        return $workspaceName.'/'.$effectiveDir;
    }

    protected function firstLine(string $output): string
    {
        $lines = array_filter(
            array_map('trim', explode("\n", str_replace("\r", '', $output))),
            fn (string $line) => $line !== ''
        );

        $first = $lines[0] ?? 'Unknown git error';

        return $first;
    }
}
