<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class WorkspaceCommandsTest extends TestCase
{
    public function test_workspace_register_registers_multi_vendor_workspace(): void
    {
        $this->artisan('workspace:register', ['path' => 'packages'])
            ->expectsOutputToContain('Workspace [packages] registered successfully')
            ->assertSuccessful();

        $workspaceData = $this->getSandboxWorkspace();
        $this->assertArrayHasKey('packages', $workspaceData['workspaces']);
        $this->assertNull($workspaceData['workspaces']['packages']['vendor']);
        $this->assertSame('packages', $workspaceData['default']);

        // Check gitignore
        $gitignore = File::get(base_path('.gitignore'));
        $this->assertStringContainsString('/packages', $gitignore);

        // Check composer.json repository contains exact key or url
        $composer = $this->getSandboxComposer();
        $repos = $composer['repositories'] ?? [];
        $found = false;
        foreach ($repos as $key => $repo) {
            if (($key === 'workspace-packages' || ($repo['name'] ?? '') === 'workspace-packages') && ($repo['url'] ?? '') === 'packages/*/*') {
                $found = true;
                break;
            }
            if (($repo['type'] ?? '') === 'path' && ($repo['url'] ?? '') === 'packages/*/*') {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected path repository for packages/*/* was not registered in composer.json.');
    }

    public function test_workspace_register_registers_fixed_vendor_workspace(): void
    {
        $this->artisan('workspace:register', [
            'path' => 'labs',
            '--vendor' => 'alex-kassel-labs',
            '--default' => true,
        ])
            ->expectsOutputToContain('Workspace [labs] registered successfully')
            ->assertSuccessful();

        $workspaceData = $this->getSandboxWorkspace();
        $this->assertArrayHasKey('labs', $workspaceData['workspaces']);
        $this->assertSame('alex-kassel-labs', $workspaceData['workspaces']['labs']['vendor']);
        $this->assertSame('labs', $workspaceData['default']);

        // Check composer.json has 1-level flat path repository
        $composer = $this->getSandboxComposer();
        $repos = $composer['repositories'] ?? [];
        $found = false;
        foreach ($repos as $key => $repo) {
            if (($key === 'workspace-labs' || ($repo['name'] ?? '') === 'workspace-labs') && ($repo['url'] ?? '') === 'labs/*') {
                $found = true;
                break;
            }
            if (($repo['type'] ?? '') === 'path' && ($repo['url'] ?? '') === 'labs/*') {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected path repository for labs/* was not registered in composer.json.');
    }

    public function test_workspace_register_rejects_duplicate_workspace(): void
    {
        Workspace::add('packages');

        $this->artisan('workspace:register', ['path' => 'packages'])
            ->expectsOutputToContain('Workspace [packages] is already registered.')
            ->assertFailed();
    }

    public function test_workspace_register_rejects_empty_path(): void
    {
        $this->artisan('workspace:register', ['path' => ''])
            ->expectsOutputToContain('Workspace path cannot be empty.')
            ->assertFailed();
    }

    public function test_workspace_register_rejects_path_traversal(): void
    {
        $this->artisan('workspace:register', ['path' => '../external'])
            ->expectsOutputToContain('Path traversal ("..") is not allowed')
            ->assertFailed();
    }

    public function test_workspace_register_rejects_absolute_paths(): void
    {
        $this->artisan('workspace:register', ['path' => '/var/evil'])
            ->expectsOutputToContain('Absolute paths are not allowed')
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

    public function test_workspace_list_is_read_only_by_default_and_syncs_with_flag(): void
    {
        Workspace::add('packages');
        // Manually create a package folder with composer.json on disk without registering in workspace.json
        $this->createDummyPackage('packages/acme/unregistered-pkg', 'acme/unregistered-pkg');

        // Without --sync, workspace:list uses load() and does not alter packages list on disk
        $this->artisan('workspace:list')
            ->assertSuccessful();

        $loaded = Workspace::load();
        $this->assertNotContains('acme/unregistered-pkg', $loaded['workspaces']['packages']['packages']);

        // With --sync, it rescans and persists newly detected packages
        $this->artisan('workspace:list', ['--sync' => true])
            ->assertSuccessful();

        Workspace::clearCache();
        $synced = Workspace::load();
        $this->assertContains('acme/unregistered-pkg', $synced['workspaces']['packages']['packages']);
    }

    public function test_workspace_list_displays_installed_and_version_information(): void
    {
        Workspace::add('packages');
        $this->createDummyPackage('packages/acme/uninstalled-pkg', 'acme/uninstalled-pkg');
        Workspace::sync();

        $this->artisan('workspace:list')
            ->expectsOutputToContain('acme/uninstalled-pkg [not installed]')
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

    public function test_workspace_unregister_unregisters_workspace_and_warns_about_git(): void
    {
        Workspace::add('packages');

        $this->artisan('workspace:unregister', ['path' => 'packages'])
            ->expectsOutputToContain('Workspace [packages] unregistered from configuration')
            ->expectsOutputToContain('CAUTION:')
            ->assertSuccessful();

        $workspaceData = $this->getSandboxWorkspace();
        $this->assertArrayNotHasKey('packages', $workspaceData['workspaces']);

        // Assert path repository is completely removed from composer.json
        $composer = $this->getSandboxComposer();
        foreach ($composer['repositories'] ?? [] as $key => $repo) {
            $name = $repo['name'] ?? (is_string($key) ? $key : '');
            $url = $repo['url'] ?? '';
            $this->assertNotSame('workspace-packages', $name, 'Repository key workspace-packages was not removed from composer.json.');
            $this->assertNotSame('packages/*/*', $url, 'Repository url packages/*/* was not removed from composer.json.');
        }

        // Physical folder still exists
        $this->assertDirectoryExists(base_path('packages'));
    }

    public function test_workspace_unregister_switches_default_workspace_to_next_available(): void
    {
        Workspace::add('primary');
        Workspace::add('secondary');
        $this->assertSame('primary', Workspace::getDefault());

        $this->artisan('workspace:unregister', ['path' => 'primary'])
            ->assertSuccessful();

        // Default must automatically switch to 'secondary'
        Workspace::clearCache();
        $this->assertSame('secondary', Workspace::getDefault());
    }

    public function test_workspace_register_and_package_make_with_nested_path(): void
    {
        // Test client-specific nested workspace e.g. clients/acme
        $this->artisan('workspace:register', [
            'path' => 'clients/acme',
            '--vendor' => 'acme-corp',
        ])
            ->expectsOutputToContain('Workspace [clients/acme] registered successfully')
            ->assertSuccessful();

        $this->artisan('package:make', [
            'package' => 'billing-portal',
            '--workspace' => 'clients/acme',
        ])
            ->expectsOutputToContain('created successfully in')
            ->assertSuccessful();

        $this->assertDirectoryExists(base_path('clients/acme/billing-portal/src'));
        $composer = json_decode(File::get(base_path('clients/acme/billing-portal/composer.json')), true);
        $this->assertSame('acme-corp/billing-portal', $composer['name']);
    }

    public function test_workspace_help_displays_guide(): void
    {
        $this->artisan('workspace:help')
            ->expectsOutputToContain('WORKSPACE DEVELOPMENT TOOLKIT')
            ->expectsOutputToContain('TWO WORKSPACE PARADIGMS')
            ->expectsOutputToContain('package:clone')
            ->assertSuccessful();
    }

    public function test_workspace_unregister_without_args_in_non_interactive_mode(): void
    {
        $this->artisan('workspace:unregister')
            ->expectsOutputToContain('[CMD_ARGUMENT_REQUIRED]')
            ->expectsOutputToContain('Available workspaces:')
            ->assertFailed();
    }

    public function test_workspace_default_without_args_in_non_interactive_mode(): void
    {
        $this->artisan('workspace:default')
            ->expectsOutputToContain('[CMD_ARGUMENT_REQUIRED]')
            ->expectsOutputToContain('Available workspaces:')
            ->assertFailed();
    }

    public function test_workspace_unregister_with_invalid_workspace_displays_available_workspaces(): void
    {
        $this->artisan('workspace:unregister', ['path' => 'non-existent-ws'])
            ->expectsOutputToContain('[WS_WORKSPACE_NOT_FOUND]')
            ->expectsOutputToContain('Available workspaces:')
            ->assertFailed();
    }

    public function test_workspace_unregister_with_active_packages_fails_in_non_interactive_mode(): void
    {
        Workspace::add('packages');
        $this->createDummyPackage('packages/acme/active-pkg', 'acme/active-pkg');

        // Mark package as active in root composer.json require-dev
        $composer = $this->getSandboxComposer();
        $composer['require-dev']['acme/active-pkg'] = '@dev';
        File::put(base_path('composer.json'), json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->artisan('workspace:unregister', ['path' => 'packages'])
            ->expectsOutputToContain('[WS_WORKSPACE_CONTAINS_ACTIVE_PACKAGES]')
            ->expectsOutputToContain('acme/active-pkg (require-dev)')
            ->expectsOutputToContain('--detach')
            ->assertFailed();

        // Workspace must still exist
        $this->assertArrayHasKey('packages', Workspace::all());
    }

    public function test_workspace_unregister_with_detach_uninstalls_active_packages(): void
    {
        Process::fake(['*' => Process::result('ok')]);

        Workspace::add('packages');
        $this->createDummyPackage('packages/acme/active-pkg', 'acme/active-pkg');

        $composer = $this->getSandboxComposer();
        $composer['require-dev']['acme/active-pkg'] = '@dev';
        File::put(base_path('composer.json'), json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->artisan('workspace:unregister', ['path' => 'packages', '--detach' => true])
            ->expectsOutputToContain('Workspace [packages] unregistered from configuration')
            ->expectsOutputToContain('Active packages were uninstalled from root composer.json')
            ->assertSuccessful();

        $this->assertArrayNotHasKey('packages', Workspace::all());
        $this->assertDirectoryExists(base_path('packages'));
    }

    public function test_workspace_unregister_with_purge_deletes_directory_and_unregisters(): void
    {
        Process::fake(['*' => Process::result('ok')]);

        Workspace::add('packages');
        $this->createDummyPackage('packages/acme/purge-pkg', 'acme/purge-pkg');

        $this->artisan('workspace:unregister', ['path' => 'packages', '--purge' => true, '--force' => true])
            ->expectsOutputToContain('permanently purged from disk and configuration')
            ->assertSuccessful();

        $this->assertArrayNotHasKey('packages', Workspace::all());
        $this->assertDirectoryDoesNotExist(base_path('packages'));
    }

    public function test_workspace_unregister_with_purge_blocks_dirty_git_repo_without_force(): void
    {
        Workspace::add('packages');
        $this->createDummyPackage('packages/acme/dirty-pkg', 'acme/dirty-pkg');
        File::ensureDirectoryExists(base_path('packages/acme/dirty-pkg/.git'));

        Process::fake(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
            if (str_contains($cmd, 'status')) {
                return Process::result("M dirty.php\n");
            }

            return Process::result('OK');
        });

        $this->artisan('workspace:unregister', ['path' => 'packages', '--purge' => true])
            ->expectsOutputToContain('packages have uncommitted changes or unpushed commits')
            ->assertFailed();

        $this->assertArrayHasKey('packages', Workspace::all());
        $this->assertDirectoryExists(base_path('packages/acme/dirty-pkg'));
    }

    public function test_workspace_local_manifest_persistence_and_auto_restoration(): void
    {
        Workspace::add('labs', 'acme', true);
        $this->createDummyPackage('labs/foo', 'acme/foo');

        // Add alias and skills
        Workspace::aliasPackage('foo', 'FooBar');
        Workspace::updatePackageSkills('labs', 'acme/foo', ['package-audit', 'testing']);

        // Assert local workspace.json exists in package directory
        $localManifest = base_path('labs/FooBar/workspace.json');
        $this->assertFileExists($localManifest);

        $json = json_decode(File::get($localManifest), true);
        $this->assertSame('acme/foo', $json['name']);
        $this->assertSame('FooBar', $json['alias']);
        $this->assertSame(['package-audit', 'testing'], $json['skills']);

        // Assert .gitignore inside package contains /workspace.json
        $pkgGitignore = File::get(base_path('labs/FooBar/.gitignore'));
        $this->assertStringContainsString('/workspace.json', $pkgGitignore);

        // Remove workspace
        Workspace::remove('labs');
        $this->assertArrayNotHasKey('labs', Workspace::all());

        // Re-register workspace and verify auto-restoration
        $this->artisan('workspace:register', ['path' => 'labs', '--vendor' => 'acme'])
            ->expectsOutputToContain('Workspace [labs] registered successfully')
            ->expectsOutputToContain('Discovered and registered 1 existing package(s)')
            ->assertSuccessful();

        // Check root workspace.json has restored package with its alias and skills
        $restoredPackages = Workspace::all()['labs']['packages'];
        $this->assertCount(1, $restoredPackages);
        $this->assertSame('foo', $restoredPackages[0]['name']);
        $this->assertSame('FooBar', $restoredPackages[0]['alias']);
        $this->assertSame(['package-audit', 'testing'], $restoredPackages[0]['skills']);
    }

    public function test_workspace_flatten_and_unflatten_commands(): void
    {
        Workspace::add('modules');
        $this->assertNull(Workspace::getWorkspaceVendor('modules'));

        // Flatten workspace to default vendor 'my-corp'
        $this->artisan('workspace:flatten', ['path' => 'modules', 'vendor' => 'my-corp'])
            ->expectsOutputToContain('Workspace [modules] flattened successfully for default vendor [my-corp]')
            ->assertSuccessful();

        Workspace::clearCache();
        $this->assertSame('my-corp', Workspace::getWorkspaceVendor('modules'));

        // Check composer.json repository is now modules/* (flat)
        $composer = $this->getSandboxComposer();
        $foundFlat = false;
        foreach ($composer['repositories'] ?? [] as $repo) {
            if (($repo['url'] ?? '') === 'modules/*') {
                $foundFlat = true;
                break;
            }
        }
        $this->assertTrue($foundFlat, 'Path repository modules/* was not updated in composer.json');

        // Unflatten workspace
        $this->artisan('workspace:unflatten', ['path' => 'modules'])
            ->expectsOutputToContain('Workspace [modules] unflattened successfully')
            ->assertSuccessful();

        Workspace::clearCache();
        $this->assertNull(Workspace::getWorkspaceVendor('modules'));

        // Check composer.json repository is back to modules/*/* (nested)
        $composer = $this->getSandboxComposer();
        $foundNested = false;
        foreach ($composer['repositories'] ?? [] as $repo) {
            if (($repo['url'] ?? '') === 'modules/*/*') {
                $foundNested = true;
                break;
            }
        }
        $this->assertTrue($foundNested, 'Path repository modules/*/* was not restored in composer.json');
    }

    public function test_workspace_register_warns_about_unversioned_directories(): void
    {
        // Create an unversioned monolithic directory inside workspace path
        File::ensureDirectoryExists(base_path('app/Domains/ISS/Billing'));
        File::put(base_path('app/Domains/ISS/Billing/BillingService.php'), '<?php class BillingService {}');

        $this->artisan('workspace:register', ['path' => 'app/Domains/ISS', '--vendor' => 'alex-kassel'])
            ->expectsOutputToContain('[WS_UNVERSIONED_DIRECTORIES_DETECTED]')
            ->expectsOutputToContain('Billing')
            ->expectsOutputToContain('excluded by root .gitignore')
            ->assertSuccessful();
    }
}
