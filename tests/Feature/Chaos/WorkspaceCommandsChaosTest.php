<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature\Chaos;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;

class WorkspaceCommandsChaosTest extends TestCase
{
    public function test_workspace_register_duplicate_fails_gracefully_with_actionable_hint(): void
    {
        // First execution creates workspace
        $this->artisan('workspace:register', ['path' => 'packages'])
            ->expectsOutputToContain('Workspace [packages] registered successfully')
            ->assertSuccessful();

        // Duplicate execution must not crash; must provide actionable guidance
        $this->artisan('workspace:register', ['path' => 'packages'])
            ->expectsOutputToContain('Workspace [packages] is already registered.')
            ->expectsOutputToContain('How to fix:')
            ->expectsOutputToContain('php artisan workspace:list')
            ->assertFailed();
    }

    public function test_workspace_unregister_non_existent_workspace_fails_with_actionable_hint(): void
    {
        $this->artisan('workspace:unregister', ['path' => 'ghost-workspace'])
            ->expectsOutputToContain('Workspace [ghost-workspace] is not registered.')
            ->expectsOutputToContain('How to fix:')
            ->assertFailed();
    }

    public function test_workspace_unregister_duplicate_execution_fails_gracefully(): void
    {
        Workspace::add('packages');

        // First remove succeeds
        $this->artisan('workspace:unregister', ['path' => 'packages'])
            ->expectsOutputToContain('Workspace [packages] unregistered')
            ->assertSuccessful();

        // Second duplicate remove fails with clear actionable error
        $this->artisan('workspace:unregister', ['path' => 'packages'])
            ->expectsOutputToContain('Workspace [packages] is not registered.')
            ->expectsOutputToContain('How to fix:')
            ->assertFailed();
    }

    public function test_workspace_default_non_existent_workspace_fails_gracefully(): void
    {
        $this->artisan('workspace:default', ['path' => 'non-existent-ws'])
            ->expectsOutputToContain('Workspace [non-existent-ws] is not registered.')
            ->expectsOutputToContain('How to fix:')
            ->assertFailed();
    }

    public function test_workspace_list_with_corrupted_workspace_json_self_heals_without_backup_files(): void
    {
        // Corrupt workspace.json with invalid JSON syntax
        File::put(base_path('workspace.json'), '{ invalid json syntax !!!');

        // Command executes without crashing and self-heals the manifest
        $this->artisan('workspace:list')
            ->assertSuccessful();

        // workspace.json is now valid JSON again
        $content = File::get(base_path('workspace.json'));
        $decoded = json_decode($content, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('workspaces', $decoded);

        // No .bak files were created
        $this->assertFileDoesNotExist(base_path('workspace.json.bak'));
        $this->assertFileDoesNotExist(base_path('workspace.json.corrupted.bak'));
    }

    public function test_workspace_sync_with_corrupted_workspace_json_self_heals(): void
    {
        File::put(base_path('workspace.json'), '{ invalid json syntax !!!');

        $this->artisan('workspace:sync')
            ->assertSuccessful();

        $decoded = json_decode(File::get(base_path('workspace.json')), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('workspaces', $decoded);
        $this->assertFileDoesNotExist(base_path('workspace.json.bak'));
    }

    public function test_workspace_register_with_corrupted_workspace_json_self_heals_and_registers_workspace(): void
    {
        File::put(base_path('workspace.json'), '{ invalid json syntax !!!');

        $this->artisan('workspace:register', ['path' => 'labs'])
            ->expectsOutputToContain('Workspace [labs] registered successfully')
            ->assertSuccessful();

        $decoded = json_decode(File::get(base_path('workspace.json')), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('labs', $decoded['workspaces']);
        $this->assertFileDoesNotExist(base_path('workspace.json.bak'));
    }
}
