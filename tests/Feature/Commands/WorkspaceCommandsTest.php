<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature\Commands;

require_once dirname(__DIR__, 2).'/TestCase.php';

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;

class WorkspaceCommandsTest extends TestCase
{
    public function test_workspace_add_registers_multi_vendor_workspace(): void
    {
        $this->artisan('workspace:add', ['path' => 'packages'])
            ->expectsOutputToContain('Workspace [packages] added successfully')
            ->assertSuccessful();

        $workspaceData = $this->getSandboxWorkspace();
        $this->assertArrayHasKey('packages', $workspaceData['workspaces']);
        $this->assertNull($workspaceData['workspaces']['packages']['vendor']);
        $this->assertSame('packages', $workspaceData['default']);

        // Check gitignore
        $gitignore = File::get(base_path('.gitignore'));
        $this->assertStringContainsString('/packages', $gitignore);

        // Check composer.json repository
        $composer = $this->getSandboxComposer();
        $this->assertNotEmpty($composer['repositories']);
    }

    public function test_workspace_add_registers_fixed_vendor_workspace(): void
    {
        $this->artisan('workspace:add', [
            'path' => 'labs',
            '--vendor' => 'alex-kassel-labs',
            '--default' => true,
        ])
            ->expectsOutputToContain('Workspace [labs] added successfully')
            ->assertSuccessful();

        $workspaceData = $this->getSandboxWorkspace();
        $this->assertArrayHasKey('labs', $workspaceData['workspaces']);
        $this->assertSame('alex-kassel-labs', $workspaceData['workspaces']['labs']['vendor']);
        $this->assertSame('labs', $workspaceData['default']);
    }

    public function test_workspace_add_rejects_duplicate_workspace(): void
    {
        Workspace::add('packages');

        $this->artisan('workspace:add', ['path' => 'packages'])
            ->expectsOutputToContain('Workspace [packages] is already registered.')
            ->assertFailed();
    }

    public function test_workspace_add_rejects_empty_path(): void
    {
        $this->artisan('workspace:add', ['path' => ''])
            ->expectsOutputToContain('Workspace path cannot be empty.')
            ->assertFailed();
    }

    public function test_workspace_list_renders_table(): void
    {
        Workspace::add('packages');
        Workspace::add('labs', 'alex-kassel-labs');

        $this->artisan('workspace:list')
            ->expectsOutputToContain('packages')
            ->expectsOutputToContain('labs')
            ->assertSuccessful();
    }

    public function test_workspace_default_switches_default(): void
    {
        Workspace::add('packages');
        Workspace::add('labs', 'alex-kassel-labs');

        $this->artisan('workspace:default', ['path' => 'labs'])
            ->expectsOutputToContain('Workspace [labs] is now the default workspace.')
            ->assertSuccessful();

        $this->assertSame('labs', Workspace::getDefault());
    }

    public function test_workspace_default_fails_for_unregistered_workspace(): void
    {
        Workspace::add('packages');

        $this->artisan('workspace:default', ['path' => 'unknown-ws'])
            ->expectsOutputToContain('Workspace [unknown-ws] is not registered')
            ->assertFailed();
    }

    public function test_workspace_remove_unregisters_workspace_and_warns_about_git(): void
    {
        Workspace::add('packages');

        $this->artisan('workspace:remove', ['path' => 'packages'])
            ->expectsOutputToContain('Workspace [packages] removed from configuration')
            ->expectsOutputToContain('CAUTION:')
            ->assertSuccessful();

        $workspaceData = $this->getSandboxWorkspace();
        $this->assertArrayNotHasKey('packages', $workspaceData['workspaces']);

        // Physical folder still exists
        $this->assertDirectoryExists(base_path('packages'));
    }

    public function test_workspace_help_displays_guide(): void
    {
        $this->artisan('workspace:help')
            ->expectsOutputToContain('WORKSPACE DEVELOPMENT TOOLKIT')
            ->expectsOutputToContain('TWO WORKSPACE PARADIGMS')
            ->assertSuccessful();
    }
}
