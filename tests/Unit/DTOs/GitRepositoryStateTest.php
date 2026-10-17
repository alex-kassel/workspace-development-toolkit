<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Unit\DTOs;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\GitRepositoryState;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;

class GitRepositoryStateTest extends TestCase
{
    public function test_synced_repository_has_no_issues(): void
    {
        $state = $this->state();

        $this->assertTrue($state->isSynced());
        $this->assertFalse($state->hasErrors());
        $this->assertSame([], $state->errors());
        $this->assertSame([], $state->warnings());
        $this->assertFalse($state->canPush());
        $this->assertFalse($state->canPull());
    }

    public function test_dirty_repository_has_blocking_error(): void
    {
        $state = $this->state(isClean: false, dirtyFilesCount: 2);

        $this->assertTrue($state->hasErrors());
        $this->assertFalse($state->canPush());
        $this->assertStringContainsString('dirty', $state->errors()[0]);
    }

    public function test_unpublished_branch_is_an_error_and_can_be_pushed(): void
    {
        $state = $this->state(upstream: null, syncStatus: 'Unpublished', ahead: 2);

        $this->assertTrue($state->hasErrors());
        $this->assertTrue($state->canPush());
        $this->assertStringContainsString('unpushed', $state->errors()[0]);
    }

    public function test_ahead_branch_is_an_error_and_can_be_pushed(): void
    {
        $state = $this->state(syncStatus: 'Ahead', ahead: 3);

        $this->assertTrue($state->hasErrors());
        $this->assertTrue($state->canPush());
    }

    public function test_behind_branch_is_a_warning_and_can_be_pulled(): void
    {
        $state = $this->state(syncStatus: 'Behind', behind: 2);

        $this->assertFalse($state->hasErrors());
        $this->assertTrue($state->canPull());
        $this->assertStringContainsString('Behind upstream', $state->warnings()[0]);
    }

    public function test_stashes_and_unpushed_tags_are_warnings(): void
    {
        $state = $this->state(stashesCount: 2, unpushedTags: ['v1.0.0']);

        $this->assertFalse($state->hasErrors());
        $this->assertCount(2, $state->warnings());
    }

    public function test_missing_package_directory_is_an_error(): void
    {
        $state = $this->state(exists: false, syncStatus: 'Missing');

        $this->assertTrue($state->hasErrors());
        $this->assertSame(['Package directory is missing'], $state->errors());
    }

    public function test_to_array_contains_expected_keys(): void
    {
        $array = $this->state()->toArray();

        foreach (['repository', 'path', 'is_host', 'sync_status', 'ahead', 'behind', 'errors', 'warnings'] as $key) {
            $this->assertArrayHasKey($key, $array);
        }
    }

    private function state(
        bool $exists = true,
        bool $isClean = true,
        int $dirtyFilesCount = 0,
        ?string $upstream = 'origin/main',
        string $syncStatus = 'Synced',
        int $ahead = 0,
        int $behind = 0,
        int $stashesCount = 0,
        array $unpushedTags = [],
    ): GitRepositoryState {
        return new GitRepositoryState(
            name: 'acme/pkg',
            path: '/tmp/acme/pkg',
            relativePath: 'packages/acme/pkg',
            isHost: false,
            exists: $exists,
            isRepository: $exists,
            branch: 'main',
            isDetached: false,
            isClean: $isClean,
            dirtyFilesCount: $dirtyFilesCount,
            dirtyFiles: [],
            upstream: $upstream,
            syncStatus: $syncStatus,
            ahead: $ahead,
            behind: $behind,
            stashesCount: $stashesCount,
            unpushedTags: $unpushedTags,
            originUrl: 'https://github.com/acme/pkg.git',
        );
    }
}
