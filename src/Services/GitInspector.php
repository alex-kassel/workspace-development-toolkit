<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Process;

class GitInspector
{
    /**
     * Check if path contains an initialized Git repository.
     */
    public function hasGitRepository(string $path): bool
    {
        $realPath = realpath($path);
        if ($realPath === false || ! is_dir($realPath)) {
            return false;
        }

        $gitDir = $realPath.DIRECTORY_SEPARATOR.'.git';

        return is_dir($gitDir) || is_file($gitDir);
    }

    /**
     * Check if the git working tree is clean (no untracked, modified or staged files).
     */
    public function isClean(string $path): bool
    {
        if (! $this->hasGitRepository($path)) {
            return true;
        }

        $result = Process::path($path)->run(['git', 'status', '--porcelain']);

        return $result->successful() && trim($result->output()) === '';
    }

    /**
     * Check if the current branch has unpushed commits compared to upstream.
     */
    public function hasUnpushedCommits(string $path): bool
    {
        if (! $this->hasGitRepository($path)) {
            return false;
        }

        $upstreamResult = Process::path($path)->run(['git', 'rev-parse', '--abbrev-ref', '@{u}']);
        if (! $upstreamResult->successful()) {
            $commitCountResult = Process::path($path)->run(['git', 'rev-list', '--count', 'HEAD']);
            if ($commitCountResult->successful() && (int) trim($commitCountResult->output()) > 0) {
                return true;
            }

            return false;
        }

        $result = Process::path($path)->run(['git', 'log', '@{u}..HEAD', '--oneline']);

        return $result->successful() && trim($result->output()) !== '';
    }

    /**
     * Check if the repository has any stashes.
     */
    public function hasStashes(string $path): bool
    {
        if (! $this->hasGitRepository($path)) {
            return false;
        }

        $result = Process::path($path)->run(['git', 'stash', 'list']);

        return $result->successful() && trim($result->output()) !== '';
    }

    /**
     * Check if HEAD is detached.
     */
    public function isDetachedHead(string $path): bool
    {
        if (! $this->hasGitRepository($path)) {
            return false;
        }

        $result = Process::path($path)->run(['git', 'symbolic-ref', '-q', 'HEAD']);

        return ! $result->successful();
    }

    /**
     * Get current branch name.
     */
    public function getCurrentBranch(string $path): ?string
    {
        if (! $this->hasGitRepository($path)) {
            return null;
        }

        $result = Process::path($path)->run(['git', 'branch', '--show-current']);
        $branch = trim($result->output());

        return ($result->successful() && $branch !== '') ? $branch : null;
    }

    /**
     * Get latest release tag if available.
     */
    public function getLatestTag(string $path): ?string
    {
        if (! $this->hasGitRepository($path)) {
            return null;
        }

        $result = Process::path($path)->run(['git', 'tag', '-l', '--sort=-v:refname']);
        if (! $result->successful()) {
            return null;
        }

        $tags = array_values(array_filter(explode("\n", trim($result->output()))));

        return ! empty($tags) ? trim($tags[0]) : null;
    }

    /**
     * Check if any remote is configured.
     */
    public function hasRemote(string $path): bool
    {
        if (! $this->hasGitRepository($path)) {
            return false;
        }

        $result = Process::path($path)->run(['git', 'remote']);

        return $result->successful() && trim($result->output()) !== '';
    }

    /**
     * Check if the git CLI executable is available in the environment.
     */
    public function isGitInstalled(): bool
    {
        try {
            $result = Process::run(['git', '--version']);
            if (! $result->successful()) {
                return false;
            }

            if ($result instanceof FakeProcessResult) {
                return true;
            }

            $output = strtolower(trim($result->output()));

            return $output === 'ok' || str_contains($output, 'git version');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Check if global Git user.name and user.email are configured.
     */
    public function isGitConfigured(): bool
    {
        return empty($this->getGitConfigurationErrors());
    }

    /**
     * Get list of missing Git configuration settings.
     *
     * @return array<int, string>
     */
    public function getGitConfigurationErrors(): array
    {
        $errors = [];

        if (! $this->isGitInstalled()) {
            $errors[] = 'Git executable is not installed or not found in system PATH.';

            return $errors;
        }

        $nameResult = Process::run(['git', 'config', '--get', 'user.name']);
        if (! $nameResult->successful() || (! ($nameResult instanceof FakeProcessResult) && trim($nameResult->output()) === '')) {
            $errors[] = 'Git user.name is not configured.';
        }

        $emailResult = Process::run(['git', 'config', '--get', 'user.email']);
        if (! $emailResult->successful() || (! ($emailResult instanceof FakeProcessResult) && trim($emailResult->output()) === '')) {
            $errors[] = 'Git user.email is not configured.';
        }

        return $errors;
    }

    /**
     * Initialize a clean Git repository, create initial scaffolding commit, and apply initial tag.
     *
     * @throws \RuntimeException
     */
    public function initializeRepository(string $path, string $packageName, string $initialTag = 'v0.0.1'): void
    {
        $configErrors = $this->getGitConfigurationErrors();
        if (! empty($configErrors)) {
            throw new \RuntimeException(implode(' ', $configErrors));
        }

        $initResult = Process::path($path)->run(['git', 'init']);
        if (! $initResult->successful()) {
            throw new \RuntimeException('Failed to initialize Git repository: '.$initResult->errorOutput());
        }

        $addResult = Process::path($path)->run(['git', 'add', '.']);
        if (! $addResult->successful()) {
            throw new \RuntimeException('Failed to stage files for initial Git commit: '.$addResult->errorOutput());
        }

        $commitResult = Process::path($path)->run([
            'git',
            'commit',
            '-m',
            "feat: scaffold initial {$packageName} package",
        ]);
        if (! $commitResult->successful()) {
            throw new \RuntimeException('Failed to create initial Git commit: '.$commitResult->errorOutput());
        }

        $tagResult = Process::path($path)->run(['git', 'tag', $initialTag]);
        if (! $tagResult->successful()) {
            throw new \RuntimeException("Failed to create initial Git tag [{$initialTag}]: ".$tagResult->errorOutput());
        }
    }

    /**
     * Resolve the git tree hash of HEAD in the target package directory.
     */
    public function getTreeHash(string $path): string
    {
        $result = Process::path($path)->run(['git', 'rev-parse', 'HEAD^{tree}']);

        return $result->successful() ? trim($result->output()) : '';
    }

    /**
     * Resolve the git commit hash of HEAD in the target package directory.
     */
    public function getCommitHash(string $path): string
    {
        $result = Process::path($path)->run(['git', 'rev-parse', 'HEAD']);

        return $result->successful() ? trim($result->output()) : '';
    }

    /**
     * Get a human-readable summary of the git working tree state.
     * e.g. "Clean", "2 modified", "1 untracked", "2 modified, 1 untracked", "No git repo".
     */
    public function getWorkingTreeSummary(string $path): string
    {
        if (! $this->hasGitRepository($path)) {
            return 'No git repo';
        }

        $result = Process::path($path)->run(['git', 'status', '--porcelain']);
        if (! $result->successful()) {
            return 'Unknown';
        }

        $output = trim($result->output());
        if ($output === '') {
            return 'Clean';
        }

        $lines = array_filter(explode("\n", $output), fn (string $line) => trim($line) !== '');
        $modifiedCount = 0;
        $untrackedCount = 0;

        foreach ($lines as $line) {
            if (str_starts_with($line, '??')) {
                $untrackedCount++;
            } else {
                $modifiedCount++;
            }
        }

        $parts = [];
        if ($modifiedCount > 0) {
            $parts[] = "{$modifiedCount} modified";
        }
        if ($untrackedCount > 0) {
            $parts[] = "{$untrackedCount} untracked";
        }

        return ! empty($parts) ? implode(', ', $parts) : 'Clean';
    }

    /**
     * Get upstream branch tracking status.
     * e.g. "Synced", "Ahead (↑2)", "Behind (↓1)", "Diverged (↑2 ↓1)", "No upstream", "No git repo".
     */
    public function getUpstreamStatus(string $path): string
    {
        if (! $this->hasGitRepository($path)) {
            return 'No git repo';
        }

        $upstreamResult = Process::path($path)->run(['git', 'rev-parse', '--abbrev-ref', '@{u}']);
        if (! $upstreamResult->successful() || trim($upstreamResult->output()) === '') {
            $commitCountResult = Process::path($path)->run(['git', 'rev-list', '--count', 'HEAD']);
            if ($commitCountResult->successful() && (int) trim($commitCountResult->output()) > 0) {
                return 'Unpublished';
            }

            return 'No upstream';
        }

        $revListResult = Process::path($path)->run(['git', 'rev-list', '--left-right', '--count', 'HEAD...@{u}']);
        if (! $revListResult->successful()) {
            return 'No upstream';
        }

        $parts = preg_split('/\s+/', trim($revListResult->output()));
        $ahead = isset($parts[0]) ? (int) $parts[0] : 0;
        $behind = isset($parts[1]) ? (int) $parts[1] : 0;

        if ($ahead === 0 && $behind === 0) {
            return 'Synced';
        }

        if ($ahead > 0 && $behind === 0) {
            return "Ahead (↑{$ahead})";
        }

        if ($ahead === 0 && $behind > 0) {
            return "Behind (↓{$behind})";
        }

        return "Diverged (↑{$ahead} ↓{$behind})";
    }
}
