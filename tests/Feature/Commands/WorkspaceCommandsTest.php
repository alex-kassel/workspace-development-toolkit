<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

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

    public function test_workspace_add_rejects_path_traversal(): void
    {
        $this->artisan('workspace:add', ['path' => '../external'])
            ->expectsOutputToContain('Path traversal ("..") is not allowed')
            ->assertFailed();
    }

    public function test_workspace_add_rejects_absolute_paths(): void
    {
        $this->artisan('workspace:add', ['path' => '/var/evil'])
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

    public function test_workspace_remove_switches_default_workspace_to_next_available(): void
    {
        Workspace::add('primary');
        Workspace::add('secondary');
        $this->assertSame('primary', Workspace::getDefault());

        $this->artisan('workspace:remove', ['path' => 'primary'])
            ->assertSuccessful();

        // Default must automatically switch to 'secondary'
        Workspace::clearCache();
        $this->assertSame('secondary', Workspace::getDefault());
    }

    public function test_workspace_add_and_package_make_with_nested_path(): void
    {
        // Test client-specific nested workspace e.g. clients/acme
        $this->artisan('workspace:add', [
            'path' => 'clients/acme',
            '--vendor' => 'acme-corp',
        ])
            ->expectsOutputToContain('Workspace [clients/acme] added successfully')
            ->assertSuccessful();

        $this->artisan('package:make', [
            'name' => 'billing-portal',
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
            ->expectsOutputToContain('workspace:clone')
            ->assertSuccessful();
    }

    public function test_workspace_clone_fails_closed_when_no_argument_or_self(): void
    {
        $this->artisan('workspace:clone')
            ->expectsOutputToContain('Please specify a repository URL/shorthand or pass the [--self] flag')
            ->assertFailed();
    }

    public function test_workspace_clone_fails_when_no_default_workspace_is_configured(): void
    {
        $this->artisan('workspace:clone', ['repository' => 'vendor/package'])
            ->expectsOutputToContain('No default workspace is currently configured')
            ->assertFailed();
    }

    public function test_workspace_clone_clones_github_shorthand_into_multi_vendor_workspace(): void
    {
        Workspace::add('packages', null, true);

        Process::fake([
            '*' => function ($process) {
                $targetPath = base_path('packages/cool-vendor/cool-package');
                File::ensureDirectoryExists($targetPath);
                File::put("{$targetPath}/composer.json", json_encode([
                    'name' => 'cool-vendor/cool-package',
                    'version' => '1.0.0',
                ]));

                return Process::result(output: 'Cloning into cool-package...');
            },
        ]);

        $this->artisan('workspace:clone', [
            'repository' => 'cool-vendor/cool-package',
        ])
            ->expectsOutputToContain('Repository successfully cloned to [packages/cool-vendor/cool-package]')
            ->assertSuccessful();

        $this->assertDirectoryExists(base_path('packages/cool-vendor/cool-package'));

        // Assert synced into workspace.json
        $workspaceData = $this->getSandboxWorkspace();
        $this->assertContains('cool-vendor/cool-package', $workspaceData['workspaces']['packages']['packages']);
    }

    public function test_workspace_clone_with_ssh_flag_normalizes_to_ssh(): void
    {
        Workspace::add('packages', null, true);

        Process::fake([
            '*' => function ($process) {
                $targetPath = base_path('packages/cool-vendor/ssh-package');
                File::ensureDirectoryExists($targetPath);
                File::put("{$targetPath}/composer.json", json_encode([
                    'name' => 'cool-vendor/ssh-package',
                ]));

                return Process::result(output: 'Cloned via SSH');
            },
        ]);

        $this->artisan('workspace:clone', [
            'repository' => 'cool-vendor/ssh-package',
            '--ssh' => true,
        ])
            ->expectsOutputToContain('Cloning [git@github.com:cool-vendor/ssh-package.git]')
            ->assertSuccessful();
    }

    public function test_workspace_clone_into_fixed_vendor_workspace(): void
    {
        Workspace::add('labs', 'alex-kassel-labs', true);

        Process::fake([
            '*' => function ($process) {
                $targetPath = base_path('labs/smart-module');
                File::ensureDirectoryExists($targetPath);
                File::put("{$targetPath}/composer.json", json_encode([
                    'name' => 'alex-kassel-labs/smart-module',
                ]));

                return Process::result(output: 'Cloned');
            },
        ]);

        $this->artisan('workspace:clone', [
            'repository' => 'alex-kassel-labs/smart-module',
        ])
            ->expectsOutputToContain('Repository successfully cloned to [labs/smart-module]')
            ->assertSuccessful();

        $this->assertDirectoryExists(base_path('labs/smart-module'));
    }

    public function test_workspace_clone_rejects_vendor_mismatch_in_fixed_vendor_workspace(): void
    {
        Workspace::add('labs', 'alex-kassel-labs', true);

        $this->artisan('workspace:clone', [
            'repository' => 'foreign-vendor/foreign-module',
        ])
            ->expectsOutputToContain('Vendor mismatch')
            ->assertFailed();
    }

    public function test_workspace_clone_with_install_flag_runs_composer_require(): void
    {
        Workspace::add('packages', null, true);

        Process::fake([
            '*' => function ($process) {
                $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

                if (str_contains($cmd, 'git clone')) {
                    $targetPath = base_path('packages/acme/insta-pkg');
                    File::ensureDirectoryExists($targetPath);
                    File::put("{$targetPath}/composer.json", json_encode([
                        'name' => 'acme/insta-pkg',
                    ]));

                    return Process::result(output: 'Cloned');
                }

                return Process::result(output: 'Symlinked');
            },
        ]);

        $this->artisan('workspace:clone', [
            'repository' => 'acme/insta-pkg',
            '--install' => true,
            '--dev' => true,
        ])
            ->expectsOutputToContain('Registering and symlinking [acme/insta-pkg] into root application')
            ->expectsOutputToContain('is now symlinked')
            ->assertSuccessful();

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return str_contains($cmd, 'composer require acme/insta-pkg:@dev') && str_contains($cmd, '--dev');
        });
    }

    public function test_workspace_clone_self_resolves_toolkit_and_installs_as_dev(): void
    {
        Workspace::add('packages', null, true);

        // Pre-create the directory & composer.json so canonicalComposerName is always resolved in test
        $targetPath = base_path('packages/alex-kassel/workspace-development-toolkit');

        Process::fake([
            '*' => function ($process) use ($targetPath) {
                $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

                if (str_contains($cmd, 'git config')) {
                    return Process::result(output: "https://github.com/alex-kassel/workspace-development-toolkit.git\n");
                }

                if (str_contains($cmd, 'git clone')) {
                    File::ensureDirectoryExists($targetPath);
                    File::put("{$targetPath}/composer.json", json_encode([
                        'name' => 'alex-kassel/workspace-development-toolkit',
                    ]));

                    return Process::result(output: 'Cloned self');
                }

                return Process::result(output: 'Symlinked self');
            },
        ]);

        $this->artisan('workspace:clone', [
            '--self' => true,
        ])
            ->expectsOutputToContain('Cloning [')
            ->expectsOutputToContain('alex-kassel/workspace-development-toolkit')
            ->assertSuccessful();

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return str_contains($cmd, 'git clone') && str_contains($cmd, 'alex-kassel/workspace-development-toolkit');
        });

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return str_contains($cmd, 'composer require') && str_contains($cmd, 'alex-kassel/workspace-development-toolkit:@dev') && str_contains($cmd, '--dev');
        });
    }

    public function test_workspace_clone_with_as_in_flat_workspace(): void
    {
        Workspace::add('app/Cores', 'alex-kassel', true);
        $targetPath = base_path('app/Cores/Scraper');

        Process::fake([
            '*' => function ($process) use ($targetPath) {
                $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

                if (str_contains($cmd, 'git clone')) {
                    File::ensureDirectoryExists($targetPath);
                    File::put("{$targetPath}/composer.json", json_encode([
                        'name' => 'alex-kassel/scraper-core',
                    ]));

                    return Process::result(output: 'Cloned into Scraper');
                }

                return Process::result(output: 'Composer done');
            },
        ]);

        $this->artisan('workspace:clone', [
            'repository' => 'alex-kassel/scraper-core',
            '--as' => 'Scraper',
        ])
            ->expectsOutputToContain('into [app/Cores/Scraper]')
            ->assertSuccessful();

        $this->assertDirectoryExists($targetPath);
        $manifest = Workspace::load();
        $this->assertSame([['name' => 'scraper-core', 'alias' => 'Scraper']], $manifest['workspaces']['app/Cores']['packages']);
    }

    public function test_workspace_clone_with_alias_rejects_nested_workspace(): void
    {
        Workspace::add('packages', null, true);

        $this->artisan('workspace:clone', [
            'repository' => 'alex-kassel/scraper-core',
            '--alias' => 'Scraper',
        ])
            ->expectsOutputToContain('Aliases are only supported in flat (fixed-vendor) workspaces')
            ->assertFailed();
    }

    public function test_workspace_clone_shorthand_resolves_via_template_and_clones(): void
    {
        Workspace::add('packages', null, true);
        $targetPath = base_path('packages/foo/bar');

        Process::fake([
            '*' => function ($process) use ($targetPath) {
                $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

                if (str_contains($cmd, 'git clone')) {
                    File::ensureDirectoryExists($targetPath);
                    File::put("{$targetPath}/composer.json", json_encode([
                        'name' => 'foo/bar',
                    ]));

                    return Process::result(output: 'Cloned into bar');
                }

                return Process::result(output: 'ok');
            },
        ]);

        $this->artisan('workspace:clone', [
            'repository' => 'foo/bar',
        ])
            ->expectsOutputToContain('into [packages/foo/bar]')
            ->assertSuccessful();

        $this->assertDirectoryExists($targetPath);
        $manifest = Workspace::load();
        $this->assertContains('foo/bar', $manifest['workspaces']['packages']['packages']);
    }

    public function test_workspace_clone_full_https_and_ssh_urls(): void
    {
        Workspace::add('packages', null, true);
        $targetPath = base_path('packages/acme/widgets');

        Process::fake([
            '*' => function ($process) use ($targetPath) {
                $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

                if (str_contains($cmd, 'git clone')) {
                    File::ensureDirectoryExists($targetPath);
                    File::put("{$targetPath}/composer.json", json_encode([
                        'name' => 'acme/widgets',
                    ]));

                    return Process::result(output: 'Cloned widgets');
                }

                return Process::result(output: 'ok');
            },
        ]);

        $this->artisan('workspace:clone', [
            'repository' => 'https://gitlab.com/acme/widgets.git',
        ])
            ->expectsOutputToContain('into [packages/acme/widgets]')
            ->assertSuccessful();

        $this->assertDirectoryExists($targetPath);
    }

    public function test_workspace_clone_with_install_and_dev_flags(): void
    {
        Workspace::add('packages', null, true);
        $targetPath = base_path('packages/acme/tools');

        Process::fake([
            '*' => function ($process) use ($targetPath) {
                $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

                if (str_contains($cmd, 'git clone')) {
                    File::ensureDirectoryExists($targetPath);
                    File::put("{$targetPath}/composer.json", json_encode([
                        'name' => 'acme/tools',
                    ]));

                    return Process::result(output: 'Cloned tools');
                }

                return Process::result(output: 'Composer install ok');
            },
        ]);

        $this->artisan('workspace:clone', [
            'repository' => 'acme/tools',
            '--install' => true,
            '--dev' => true,
        ])
            ->expectsOutputToContain('Registering and symlinking [acme/tools]')
            ->assertSuccessful();

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return str_contains($cmd, 'composer require') && str_contains($cmd, 'acme/tools:@dev') && str_contains($cmd, '--dev');
        });
    }

    public function test_workspace_clone_handles_git_failure_gracefully(): void
    {
        Workspace::add('packages', null, true);

        Process::fake([
            '*' => Process::result(
                output: '',
                errorOutput: 'fatal: repository not found or access denied',
                exitCode: 128
            ),
        ]);

        $this->artisan('workspace:clone', [
            'repository' => 'secret/private-repo',
        ])
            ->expectsOutputToContain('Failed to clone repository')
            ->expectsOutputToContain('fatal: repository not found or access denied')
            ->assertFailed();

        $this->assertDirectoryDoesNotExist(base_path('packages/secret/private-repo'));
    }
}
