<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

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
}
