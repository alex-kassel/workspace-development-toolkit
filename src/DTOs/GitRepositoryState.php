<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\DTOs;

final readonly class GitRepositoryState
{
    /**
     * @param  array<int, string>  $dirtyFiles
     * @param  array<int, string>  $unpushedTags
     */
    public function __construct(
        public string $name,
        public string $path,
        public string $relativePath,
        public bool $isHost = false,
        public bool $exists = true,
        public bool $isRepository = false,
        public ?string $branch = null,
        public bool $isDetached = false,
        public bool $isClean = true,
        public int $dirtyFilesCount = 0,
        public array $dirtyFiles = [],
        public ?string $upstream = null,
        public string $syncStatus = 'No git repo',
        public int $ahead = 0,
        public int $behind = 0,
        public int $stashesCount = 0,
        public array $unpushedTags = [],
        public ?string $originUrl = null,
    ) {}

    /**
     * Whether this repository is fully synchronized with its upstream.
     */
    public function isSynced(): bool
    {
        return $this->isRepository
            && $this->isClean
            && ! $this->isDetached
            && $this->originUrl !== null
            && $this->syncStatus === 'Synced';
    }

    /**
     * Whether this repository has local commits that can be pushed.
     */
    public function canPush(): bool
    {
        return $this->isRepository
            && $this->exists
            && $this->isClean
            && ! $this->isDetached
            && $this->originUrl !== null
            && in_array($this->syncStatus, ['Ahead', 'Unpublished'], true);
    }

    /**
     * Whether this repository is behind its upstream and can be fast-forwarded.
     */
    public function canPull(): bool
    {
        return $this->isRepository
            && $this->exists
            && $this->isClean
            && ! $this->isDetached
            && $this->upstream !== null
            && $this->syncStatus === 'Behind';
    }

    /**
     * Human-readable issues that must block the workflow.
     *
     * @return array<int, string>
     */
    public function errors(): array
    {
        if (! $this->exists) {
            return ['Package directory is missing'];
        }

        if (! $this->isRepository) {
            return $this->isHost
                ? []
                : ['Not an independent git repository'];
        }

        $errors = [];

        if ($this->isDetached) {
            $errors[] = 'HEAD is detached from any branch';
        }

        if (! $this->isClean) {
            $errors[] = sprintf('Working tree is dirty (%d file(s))', $this->dirtyFilesCount);
        }

        if ($this->originUrl === null) {
            $errors[] = 'No "origin" remote configured';
        }

        if ($this->syncStatus === 'Unpublished') {
            $errors[] = sprintf('Branch has %d unpushed commit(s) and no upstream', $this->ahead);
        } elseif ($this->syncStatus === 'Ahead') {
            $errors[] = sprintf('%d commit(s) not pushed to upstream', $this->ahead);
        } elseif ($this->syncStatus === 'Diverged') {
            $errors[] = sprintf('Branch has diverged from upstream (↑%d ↓%d)', $this->ahead, $this->behind);
        } elseif ($this->syncStatus === 'No upstream') {
            $errors[] = 'Branch has no upstream';
        }

        return $errors;
    }

    /**
     * Non-blocking issues worth surfacing to the user.
     *
     * @return array<int, string>
     */
    public function warnings(): array
    {
        if (! $this->isRepository) {
            return $this->isHost ? ['Host application is not a git repository'] : [];
        }

        $warnings = [];

        if ($this->syncStatus === 'Behind') {
            $warnings[] = sprintf('Behind upstream by %d commit(s)', $this->behind);
        }

        if ($this->stashesCount > 0) {
            $warnings[] = sprintf('%d stash(es) are not transferred to other machines', $this->stashesCount);
        }

        if ($this->unpushedTags !== []) {
            $warnings[] = sprintf('%d tag(s) are not pushed: %s', count($this->unpushedTags), implode(', ', $this->unpushedTags));
        }

        return $warnings;
    }

    public function hasErrors(): bool
    {
        return $this->errors() !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'repository' => $this->name,
            'path' => $this->relativePath,
            'is_host' => $this->isHost,
            'exists' => $this->exists,
            'is_repository' => $this->isRepository,
            'branch' => $this->branch,
            'detached' => $this->isDetached,
            'clean' => $this->isClean,
            'dirty_files_count' => $this->dirtyFilesCount,
            'upstream' => $this->upstream,
            'sync_status' => $this->syncStatus,
            'ahead' => $this->ahead,
            'behind' => $this->behind,
            'stashes_count' => $this->stashesCount,
            'unpushed_tags' => $this->unpushedTags,
            'origin_url' => $this->originUrl,
            'errors' => $this->errors(),
            'warnings' => $this->warnings(),
        ];
    }
}
