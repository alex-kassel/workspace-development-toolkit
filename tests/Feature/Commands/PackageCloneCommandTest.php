<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class PackageCloneCommandTest extends TestCase
{
    public function test_package_clone_fails_closed_when_no_argument_or_self(): void
    {
        $this->artisan('package:clone')
            ->expectsOutputToContain('Please specify a repository URL/shorthand or pass the [--self] flag')
            ->assertFailed();
    }

    public function test_package_clone_fails_when_no_default_workspace_is_configured(): void
    {
        $this->artisan('package:clone', ['package' => 'vendor/package'])
            ->expectsOutputToContain('No default workspace is currently configured')
            ->assertFailed();
    }

    public function test_package_clone_clones_github_shorthand_into_multi_vendor_workspace(): void
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

        $this->artisan('package:clone', [
            'package' => 'cool-vendor/cool-package',
        ])
            ->expectsOutputToContain('Repository successfully cloned to [packages/cool-vendor/cool-package]')
            ->assertSuccessful();

        $this->assertDirectoryExists(base_path('packages/cool-vendor/cool-package'));

        // Assert synced into workspace.json
        $workspaceData = $this->getSandboxWorkspace();
        $this->assertContains('cool-vendor/cool-package', $workspaceData['workspaces']['packages']['packages']);
    }

    public function test_package_clone_with_ssh_flag_normalizes_to_ssh(): void
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

        $this->artisan('package:clone', [
            'package' => 'cool-vendor/ssh-package',
            '--ssh' => true,
        ])
            ->expectsOutputToContain('Cloning [git@github.com:cool-vendor/ssh-package.git]')
            ->assertSuccessful();
    }

    public function test_package_clone_into_fixed_vendor_workspace(): void
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

        $this->artisan('package:clone', [
            'package' => 'alex-kassel-labs/smart-module',
        ])
            ->expectsOutputToContain('Repository successfully cloned to [labs/smart-module]')
            ->assertSuccessful();

        $this->assertDirectoryExists(base_path('labs/smart-module'));
    }

    public function test_package_clone_rejects_vendor_mismatch_in_fixed_vendor_workspace(): void
    {
        Workspace::add('labs', 'alex-kassel-labs', true);

        $this->artisan('package:clone', [
            'package' => 'foreign-vendor/foreign-module',
        ])
            ->expectsOutputToContain('Vendor mismatch')
            ->assertFailed();
    }

    public function test_package_clone_with_install_flag_runs_composer_require(): void
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

        $this->artisan('package:clone', [
            'package' => 'acme/insta-pkg',
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

    public function test_package_clone_self_resolves_toolkit_and_installs_as_dev(): void
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
                    File::ensureDirectoryExists("{$targetPath}/stubs");
                    File::put("{$targetPath}/stubs/AGENTS.md.stub", '# Agents Stub');

                    return Process::result(output: 'Cloned self');
                }

                return Process::result(output: 'Symlinked self');
            },
        ]);

        $this->artisan('package:clone', [
            '--self' => true,
        ])
            ->expectsOutputToContain('Cloning [')
            ->expectsOutputToContain('Linked host [AGENTS.md]')
            ->assertSuccessful();

        $this->assertTrue(is_link(base_path('AGENTS.md')));

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return str_contains($cmd, 'git clone') && str_contains($cmd, 'alex-kassel/workspace-development-toolkit');
        });

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return str_contains($cmd, 'composer require') && str_contains($cmd, 'alex-kassel/workspace-development-toolkit:@dev') && str_contains($cmd, '--dev');
        });
    }

    public function test_package_clone_with_as_in_flat_workspace(): void
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

        $this->artisan('package:clone', [
            'package' => 'alex-kassel/scraper-core',
            '--as' => 'Scraper',
        ])
            ->expectsOutputToContain('into [app/Cores/Scraper]')
            ->assertSuccessful();

        $this->assertDirectoryExists($targetPath);
        $manifest = Workspace::load();
        $this->assertSame([['name' => 'scraper-core', 'alias' => 'Scraper']], $manifest['workspaces']['app/Cores']['packages']);
    }

    public function test_package_clone_with_alias_rejects_nested_workspace(): void
    {
        Workspace::add('packages', null, true);

        $this->artisan('package:clone', [
            'package' => 'alex-kassel/scraper-core',
            '--alias' => 'Scraper',
        ])
            ->expectsOutputToContain('Aliases are only supported in flat (fixed-vendor) workspaces')
            ->assertFailed();
    }

    public function test_package_clone_shorthand_resolves_via_template_and_clones(): void
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

        $this->artisan('package:clone', [
            'package' => 'foo/bar',
        ])
            ->expectsOutputToContain('into [packages/foo/bar]')
            ->assertSuccessful();

        $this->assertDirectoryExists($targetPath);
        $manifest = Workspace::load();
        $this->assertContains('foo/bar', $manifest['workspaces']['packages']['packages']);
    }

    public function test_package_clone_full_https_and_ssh_urls(): void
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

        $this->artisan('package:clone', [
            'package' => 'https://gitlab.com/acme/widgets.git',
        ])
            ->expectsOutputToContain('into [packages/acme/widgets]')
            ->assertSuccessful();

        $this->assertDirectoryExists($targetPath);
    }

    public function test_package_clone_with_install_and_dev_flags(): void
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

        $this->artisan('package:clone', [
            'package' => 'acme/tools',
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

    public function test_package_clone_handles_git_failure_gracefully(): void
    {
        Workspace::add('packages', null, true);

        Process::fake([
            '*' => Process::result(
                output: '',
                errorOutput: 'fatal: repository not found or access denied',
                exitCode: 128
            ),
        ]);

        $this->artisan('package:clone', [
            'package' => 'secret/private-repo',
        ])
            ->expectsOutputToContain('Failed to clone repository')
            ->expectsOutputToContain('fatal: repository not found or access denied')
            ->assertFailed();

        $this->assertDirectoryDoesNotExist(base_path('packages/secret/private-repo'));
    }

    public function test_package_clone_rejects_path_traversal_in_alias(): void
    {
        Workspace::add('labs', 'alex-kassel-labs', true);

        $this->artisan('package:clone', [
            'package' => 'alex-kassel-labs/tool',
            '--as' => '../../outside',
        ])
            ->expectsOutputToContain('Invalid alias [../../outside]')
            ->assertFailed();
    }

    public function test_package_clone_recursive_clones_trusted_dependencies(): void
    {
        Workspace::add('packages', null, true);
        config(['workspace.trusted_organizations' => ['acme']]);

        Process::fake([
            '*' => function ($process) {
                $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

                if (str_contains($cmd, 'acme/main-package')) {
                    $targetPath = base_path('packages/acme/main-package');
                    File::ensureDirectoryExists($targetPath);
                    File::put("{$targetPath}/composer.json", json_encode([
                        'name' => 'acme/main-package',
                        'require' => [
                            'acme/sub-package' => '^1.0',
                            'untrusted/other-package' => '^2.0',
                        ],
                    ]));

                    return Process::result(output: 'Cloned main-package');
                }

                if (str_contains($cmd, 'acme/sub-package')) {
                    $targetPath = base_path('packages/acme/sub-package');
                    File::ensureDirectoryExists($targetPath);
                    File::put("{$targetPath}/composer.json", json_encode([
                        'name' => 'acme/sub-package',
                        'require' => [
                            'acme/main-package' => '^1.0', // Circular reference back to main
                        ],
                    ]));

                    return Process::result(output: 'Cloned sub-package');
                }

                return Process::result(output: 'OK');
            },
        ]);

        $this->artisan('package:clone', [
            'package' => 'acme/main-package',
            '--recursive' => true,
        ])
            ->expectsOutputToContain('Repository successfully cloned to [packages/acme/main-package]')
            ->expectsOutputToContain('Recursively cloning dependency [acme/sub-package]')
            ->doesntExpectOutputToContain('Recursively cloning dependency [untrusted/other-package]')
            ->assertSuccessful();

        $this->assertDirectoryExists(base_path('packages/acme/main-package'));
        $this->assertDirectoryExists(base_path('packages/acme/sub-package'));
        $this->assertDirectoryDoesNotExist(base_path('packages/untrusted/other-package'));

        $workspaceData = $this->getSandboxWorkspace();
        $this->assertContains('acme/main-package', $workspaceData['workspaces']['packages']['packages']);
        $this->assertContains('acme/sub-package', $workspaceData['workspaces']['packages']['packages']);
    }

    public function test_package_clone_recursive_auto_trusts_root_vendor_without_config(): void
    {
        Workspace::add('packages', null, true);
        config(['workspace.trusted_organizations' => []]);

        Process::fake([
            '*' => function ($process) {
                $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

                if (str_contains($cmd, 'acme/main-package')) {
                    $targetPath = base_path('packages/acme/main-package');
                    File::ensureDirectoryExists($targetPath);
                    File::put("{$targetPath}/composer.json", json_encode([
                        'name' => 'acme/main-package',
                        'require' => [
                            'acme/sub-package' => '^1.0',
                            'untrusted/other-package' => '^2.0',
                        ],
                    ]));

                    return Process::result(output: 'Cloned main-package');
                }

                if (str_contains($cmd, 'acme/sub-package')) {
                    $targetPath = base_path('packages/acme/sub-package');
                    File::ensureDirectoryExists($targetPath);
                    File::put("{$targetPath}/composer.json", json_encode([
                        'name' => 'acme/sub-package',
                    ]));

                    return Process::result(output: 'Cloned sub-package');
                }

                return Process::result(output: 'OK');
            },
        ]);

        $this->artisan('package:clone', [
            'package' => 'acme/main-package',
            '--recursive' => true,
        ])
            ->expectsOutputToContain('Repository successfully cloned to [packages/acme/main-package]')
            ->expectsOutputToContain('Recursively cloning dependency [acme/sub-package]')
            ->doesntExpectOutputToContain('Recursively cloning dependency [untrusted/other-package]')
            ->assertSuccessful();

        $this->assertDirectoryExists(base_path('packages/acme/main-package'));
        $this->assertDirectoryExists(base_path('packages/acme/sub-package'));
        $this->assertDirectoryDoesNotExist(base_path('packages/untrusted/other-package'));
    }

    public function test_package_clone_outputs_ssh_diagnostic_guide_on_auth_failure(): void
    {
        Workspace::add('packages', null, true);

        Process::fake([
            '*' => Process::result(
                output: '',
                errorOutput: "git@github.com: Permission denied (publickey).\nfatal: Could not read from remote repository.",
                exitCode: 128
            ),
        ]);

        $this->artisan('package:clone', [
            'package' => 'vendor/private-package',
        ])
            ->expectsOutputToContain('Failed to clone repository')
            ->expectsOutputToContain('SSH Key Missing or Rejected')
            ->expectsOutputToContain('ssh-add -l')
            ->assertFailed();

        $this->assertDirectoryDoesNotExist(base_path('packages/vendor/private-package'));
    }

    public function test_package_clone_fails_helpfully_when_target_directory_already_exists(): void
    {
        Workspace::add('packages', null, true);

        $dir = base_path('packages/acme/existing-pkg');
        File::ensureDirectoryExists($dir);
        File::put("{$dir}/composer.json", json_encode([
            'name' => 'acme/existing-pkg',
        ]));

        $this->artisan('package:clone', [
            'package' => 'acme/existing-pkg',
        ])
            ->expectsOutputToContain('Target directory [packages/acme/existing-pkg] already exists')
            ->expectsOutputToContain('php artisan package:install acme/existing-pkg')
            ->expectsOutputToContain('git -C packages/acme/existing-pkg pull')
            ->expectsOutputToContain('php artisan package:delete acme/existing-pkg')
            ->assertFailed();
    }

    public function test_package_clone_interactive_prompt_triggers_install_on_yes(): void
    {
        Workspace::add('packages', null, true);
        $targetPath = base_path('packages/cool/interactive-pkg');

        Process::fake([
            '*' => function ($process) use ($targetPath) {
                File::ensureDirectoryExists($targetPath);
                File::put("{$targetPath}/composer.json", json_encode([
                    'name' => 'cool/interactive-pkg',
                ]));

                return Process::result(output: 'OK');
            },
        ]);

        $this->artisan('package:clone', [
            'package' => 'cool/interactive-pkg',
        ])
            ->expectsConfirmation('Would you like to link [cool/interactive-pkg] into Composer now?', 'yes')
            ->expectsOutputToContain('Repository successfully cloned to [packages/cool/interactive-pkg]')
            ->assertSuccessful();
    }
}
