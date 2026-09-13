<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;

class WorkspaceSyncCommandTest extends TestCase
{
    public function test_workspace_sync_command_synchronizes_repositories_and_is_idempotent(): void
    {
        Workspace::add('packages', null, true);

        // First run should confirm synchronization
        $this->artisan('workspace:sync')
            ->expectsOutputToContain('path repositories')
            ->assertSuccessful();

        // Check standalone workspace CLI exists
        $this->assertFileExists(base_path('workspace'));

        // Second run without changes should report already up to date (0 file changes)
        $this->artisan('workspace:sync')
            ->expectsOutputToContain('already up to date')
            ->assertSuccessful();
    }

    public function test_workspace_sync_command_supports_dry_run(): void
    {
        Workspace::add('packages');

        $this->artisan('workspace:sync', ['--dry-run' => true])
            ->expectsOutputToContain('dry-run')
            ->expectsOutputToContain('packages')
            ->assertSuccessful();
    }
}
