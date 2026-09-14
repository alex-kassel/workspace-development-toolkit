<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature\Chaos;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;

class PackageCommandsChaosTest extends TestCase
{
    public function test_package_make_duplicate_execution_fails_with_actionable_hint(): void
    {
        Workspace::add('packages', null, true);

        // First creation succeeds
        $this->artisan('package:make', ['package' => 'acme/my-pkg'])
            ->expectsOutputToContain('created successfully')
            ->assertSuccessful();

        // Duplicate creation fails with actionable guidance
        $this->artisan('package:make', ['package' => 'acme/my-pkg'])
            ->expectsOutputToContain('already exists on disk')
            ->expectsOutputToContain('How to fix:')
            ->assertFailed();
    }

    public function test_package_delete_duplicate_execution_fails_with_actionable_hint(): void
    {
        Workspace::add('packages', null, true);

        $this->artisan('package:make', ['package' => 'acme/temp-pkg'])
            ->assertSuccessful();

        // First delete succeeds
        $this->artisan('package:delete', ['package' => 'acme/temp-pkg', '--force' => true])
            ->expectsOutputToContain('permanently deleted')
            ->assertSuccessful();

        // Second delete fails gracefully
        $this->artisan('package:delete', ['package' => 'acme/temp-pkg', '--force' => true])
            ->expectsOutputToContain('not found in any registered workspace')
            ->expectsOutputToContain('How to fix:')
            ->expectsOutputToContain('php artisan workspace:list')
            ->assertFailed();
    }

    public function test_package_make_rejects_unregistered_directory_and_package_delete_refuses_unregistered_directory(): void
    {
        Workspace::add('packages', null, true);

        // Manually create an alien or custom directory
        $manualDir = base_path('packages/acme/manual-folder');
        File::ensureDirectoryExists($manualDir);
        File::put($manualDir.'/notes.txt', 'some manual notes');

        // 1. package:make detects directory is already taken by a non-package directory and instructs manual removal
        $this->artisan('package:make', ['package' => 'acme/manual-folder'])
            ->expectsOutputToContain('already exists on disk and is not a registered workspace package')
            ->expectsOutputToContain('Inspect folder contents:')
            ->assertFailed();

        // 2. package:delete REFUSES to delete any folder not registered in workspace.json
        $this->artisan('package:delete', ['package' => 'acme/manual-folder', '--force' => true])
            ->expectsOutputToContain('was not found in any registered workspace')
            ->assertFailed();

        // Directory is safely preserved on disk
        $this->assertDirectoryExists($manualDir);

        // 3. User manually removes the directory
        File::deleteDirectory($manualDir);

        // 4. Now package:make succeeds cleanly
        $this->artisan('package:make', ['package' => 'acme/manual-folder'])
            ->expectsOutputToContain('created successfully')
            ->assertSuccessful();
    }

    public function test_package_commands_handle_ambiguous_package_gracefully_without_crashing(): void
    {
        // Setup two workspaces: one nested ('packages') and one flat ('labs')
        Workspace::add('packages', null, true);
        Workspace::add('labs', 'alex-kassel-labs');

        // Create package in packages: 'acme/tools'
        $this->createDummyPackage('packages/acme/tools', 'acme/tools');

        // Create package in labs: 'alex-kassel-labs/tools'
        $this->createDummyPackage('labs/tools', 'alex-kassel-labs/tools');

        Workspace::sync();

        // Testing 'tools' reference: both packages share short name 'tools'
        // 1. package:check must not crash with unhandled AmbiguousPackageException
        $this->artisan('package:check', ['package' => 'tools'])
            ->expectsOutputToContain('Ambiguous package reference')
            ->expectsOutputToContain('How to fix:')
            ->assertFailed();

        // 2. package:deps must not crash
        $this->artisan('package:deps', ['package' => 'tools'])
            ->expectsOutputToContain('Ambiguous package reference')
            ->expectsOutputToContain('How to fix:')
            ->assertFailed();

        // 3. package:skills must not crash
        $this->artisan('package:skills', ['package' => 'tools'])
            ->expectsOutputToContain('Ambiguous package reference')
            ->expectsOutputToContain('How to fix:')
            ->assertFailed();

        // 4. package:workflow must not crash
        $this->artisan('package:workflow', ['package' => 'tools'])
            ->expectsOutputToContain('Ambiguous package reference')
            ->expectsOutputToContain('How to fix:')
            ->assertFailed();

        // 5. package:audit must not crash
        $this->artisan('package:audit', ['package' => 'tools'])
            ->expectsOutputToContain('Ambiguous package reference')
            ->expectsOutputToContain('How to fix:')
            ->assertFailed();
    }

    public function test_package_readme_missing_package_gives_actionable_hint(): void
    {
        Workspace::add('packages', null, true);

        $this->artisan('package:readme', ['package' => 'ghost/package'])
            ->expectsOutputToContain('not found')
            ->expectsOutputToContain('How to fix:')
            ->assertFailed();
    }

    public function test_package_release_check_missing_package_gives_actionable_hint(): void
    {
        Workspace::add('packages', null, true);

        $this->artisan('package:release-check', ['package' => 'ghost/package'])
            ->expectsOutputToContain('not found')
            ->expectsOutputToContain('How to fix:')
            ->assertFailed();
    }

    public function test_package_skills_missing_package_gives_actionable_hint(): void
    {
        Workspace::add('packages', null, true);

        $this->artisan('package:skills', ['package' => 'ghost/package'])
            ->expectsOutputToContain('not found')
            ->expectsOutputToContain('How to fix:')
            ->expectsOutputToContain('php artisan workspace:list')
            ->assertFailed();
    }
}
