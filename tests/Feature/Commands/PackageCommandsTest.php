<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\PackageScaffolder;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class PackageCommandsTest extends TestCase
{
    public function test_package_make_scaffolds_multi_vendor_package(): void
    {
        Workspace::add('packages', null, true);

        $this->artisan('package:make', ['package' => 'acme/billing-module'])
            ->expectsOutputToContain('created successfully in')
            ->assertSuccessful();

        $packageDir = base_path('packages/acme/billing-module');
        $this->assertDirectoryExists($packageDir);
        $this->assertFileExists($packageDir.'/composer.json');
        $this->assertFileExists($packageDir.'/src/BillingModuleServiceProvider.php');

        // Check generated composer.json content
        $composerContent = json_decode(File::get($packageDir.'/composer.json'), true);
        $this->assertSame('acme/billing-module', $composerContent['name']);
        $this->assertArrayHasKey('php', $composerContent['require']);
        $this->assertArrayHasKey('illuminate/support', $composerContent['require']);
        $this->assertStringContainsString('^11.0', $composerContent['require']['illuminate/support']);
        $currentMajor = (int) explode('.', app()->version())[0];
        $this->assertStringContainsString("^{$currentMajor}.0", $composerContent['require']['illuminate/support']);

        // Assert ServiceProvider content is syntactically complete
        $spContent = File::get($packageDir.'/src/BillingModuleServiceProvider.php');
        $this->assertStringContainsString('namespace Acme\BillingModule;', $spContent);
        $this->assertStringContainsString('class BillingModuleServiceProvider extends ServiceProvider', $spContent);
        $this->assertStringContainsString('public function register(): void', $spContent);
        $this->assertStringContainsString('public function boot(): void', $spContent);
    }

    public function test_package_make_fails_if_package_already_exists(): void
    {
        Workspace::add('packages', null, true);

        $this->artisan('package:make', ['package' => 'acme/existing-pkg'])
            ->assertSuccessful();

        // Second attempt must fail cleanly
        $this->artisan('package:make', ['package' => 'acme/existing-pkg'])
            ->expectsOutputToContain('already exists')
            ->assertFailed();
    }

    public function test_package_make_rejects_dev_option_without_install_option(): void
    {
        Workspace::add('packages', null, true);

        $this->artisan('package:make', [
            'package' => 'acme/dev-only-pkg',
            '--dev' => true,
        ])
            ->expectsOutputToContain('The [--dev] option can only be used in combination with [--install]')
            ->assertFailed();
    }

    public function test_package_make_scaffolds_flat_fixed_vendor_package(): void
    {
        Workspace::add('labs', 'alex-kassel-labs', true);

        $this->artisan('package:make', ['package' => 'demo-bot'])
            ->expectsOutputToContain('created successfully in')
            ->assertSuccessful();

        $packageDir = base_path('labs/demo-bot');
        $this->assertDirectoryExists($packageDir);
        $this->assertFileExists($packageDir.'/composer.json');
        $this->assertFileExists($packageDir.'/src/DemoBotServiceProvider.php');

        $composerContent = json_decode(File::get($packageDir.'/composer.json'), true);
        $this->assertSame('alex-kassel-labs/demo-bot', $composerContent['name']);

        $spContent = File::get($packageDir.'/src/DemoBotServiceProvider.php');
        $this->assertStringContainsString('namespace AlexKasselLabs\DemoBot;', $spContent);
        $this->assertStringContainsString('class DemoBotServiceProvider extends ServiceProvider', $spContent);
    }

    public function test_package_make_with_as_scaffolds_into_alias_directory_and_updates_manifest(): void
    {
        Workspace::add('app/Cores', 'alex-kassel', true);

        $this->artisan('package:make', [
            'package' => 'scraper-core',
            '--as' => 'Scraper',
        ])
            ->expectsOutputToContain('created successfully in')
            ->assertSuccessful();

        $packageDir = base_path('app/Cores/Scraper');
        $this->assertDirectoryExists($packageDir);
        $this->assertDirectoryDoesNotExist(base_path('app/Cores/scraper-core'));
        $this->assertFileExists($packageDir.'/composer.json');

        $composerContent = json_decode(File::get($packageDir.'/composer.json'), true);
        $this->assertSame('alex-kassel/scraper-core', $composerContent['name']);

        $manifest = Workspace::load();
        $this->assertSame([['name' => 'scraper-core', 'alias' => 'Scraper']], $manifest['workspaces']['app/Cores']['packages']);
    }

    public function test_package_make_with_alias_rejects_nested_workspace(): void
    {
        Workspace::add('packages', null, true);

        $this->artisan('package:make', [
            'package' => 'acme/foo-pkg',
            '--alias' => 'Foo',
        ])
            ->expectsOutputToContain('Aliases are only supported in flat (fixed-vendor) workspaces')
            ->assertFailed();
    }

    public function test_package_make_rejects_vendor_mismatch_in_fixed_vendor_workspace(): void
    {
        Workspace::add('labs', 'alex-kassel-labs', true);

        $this->artisan('package:make', ['package' => 'wrong-vendor/my-tool'])
            ->expectsOutputToContain('has a fixed vendor [alex-kassel-labs], but [wrong-vendor] was provided')
            ->assertFailed();
    }

    public function test_package_make_fails_when_no_default_workspace_is_configured(): void
    {
        // Sandbox has no workspaces registered
        $this->artisan('package:make', ['package' => 'acme/test-pkg'])
            ->expectsOutputToContain('No default workspace is currently configured')
            ->assertFailed();
    }

    public function test_package_make_rejects_invalid_composer_name(): void
    {
        Workspace::add('packages', null, true);

        $this->artisan('package:make', ['package' => 'invalid vendor/package!'])
            ->expectsOutputToContain('Composer vendor and package names must contain only lowercase letters')
            ->assertFailed();
    }

    public function test_package_make_preserves_dots_and_underscores_in_identity(): void
    {
        Workspace::add('packages', null, true);

        $this->artisan('package:make', ['package' => 'vendor_a/pkg.b'])
            ->expectsOutputToContain('created successfully in')
            ->assertSuccessful();

        $this->assertDirectoryExists(base_path('packages/vendor_a/pkg.b'));
    }

    public function test_package_make_with_install_invokes_composer_require(): void
    {
        Workspace::add('packages', null, true);

        Process::fake([
            '*' => Process::result(output: 'Success'),
        ]);

        $this->artisan('package:make', [
            'package' => 'acme/installable-pkg',
            '--install' => true,
        ])
            ->assertSuccessful();

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return str_contains($cmd, 'composer require acme/installable-pkg') && ! str_contains($cmd, '--dev');
        });
    }

    public function test_package_make_with_install_and_dev_invokes_composer_require_dev(): void
    {
        Workspace::add('packages', null, true);

        Process::fake([
            '*' => Process::result(output: 'Success'),
        ]);

        $this->artisan('package:make', [
            'package' => 'acme/dev-pkg',
            '--install' => true,
            '--dev' => true,
        ])
            ->assertSuccessful();

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return str_contains($cmd, 'composer require acme/dev-pkg') && str_contains($cmd, '--dev');
        });
    }

    public function test_package_make_recovers_gracefully_when_install_fails(): void
    {
        Workspace::add('packages', null, true);

        Process::fake([
            '*git*' => Process::result(output: 'ok'),
            '*' => Process::result(exitCode: 1, errorOutput: 'Dependency conflict'),
        ]);

        $this->artisan('package:make', [
            'package' => 'acme/failed-install-pkg',
            '--install' => true,
        ])
            ->expectsOutputToContain('automatic Composer installation failed')
            ->expectsOutputToContain('Physical files remain intact')
            ->assertFailed();

        // Physical files must remain intact
        $this->assertDirectoryExists(base_path('packages/acme/failed-install-pkg'));
    }

    public function test_package_install_runs_composer_require(): void
    {
        Workspace::add('packages', null, true);
        $this->createDummyPackage('packages/acme/my-lib', 'acme/my-lib');
        Workspace::sync();

        Process::fake([
            '*' => Process::result(output: 'Installed'),
        ]);

        $this->artisan('package:install', ['package' => 'acme/my-lib'])
            ->expectsOutputToContain('installed successfully')
            ->assertSuccessful();

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return str_contains($cmd, 'composer require acme/my-lib') && ! str_contains($cmd, '--dev');
        });
    }

    public function test_package_install_with_dev_option_runs_composer_require_dev(): void
    {
        Workspace::add('packages', null, true);
        $this->createDummyPackage('packages/acme/my-dev-lib', 'acme/my-dev-lib');
        Workspace::sync();

        Process::fake([
            '*' => Process::result(output: 'Installed dev'),
        ]);

        $this->artisan('package:install', [
            'package' => 'acme/my-dev-lib',
            '--dev' => true,
        ])
            ->expectsOutputToContain('installed successfully')
            ->assertSuccessful();

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return str_contains($cmd, 'composer require acme/my-dev-lib') && str_contains($cmd, '--dev');
        });
    }

    public function test_package_install_displays_structured_diagnostics_on_composer_failure(): void
    {
        Workspace::add('packages', null, true);
        $this->createDummyPackage('packages/acme/my-lib', 'acme/my-lib');
        Workspace::sync();

        Process::fake([
            '*' => Process::result(
                output: '',
                errorOutput: 'requires dep dev-main -> could not be found in any version, but it does match your minimum-stability.',
                exitCode: 2
            ),
        ]);

        $this->artisan('package:install', ['package' => 'acme/my-lib'])
            ->expectsOutputToContain('Failed to install package [acme/my-lib] via Composer.')
            ->expectsOutputToContain('Stability Mismatch')
            ->expectsOutputToContain('composer config minimum-stability dev')
            ->assertFailed();
    }

    public function test_package_install_resolves_short_name_in_fixed_vendor_workspace(): void
    {
        Workspace::add('labs', 'alex-kassel-labs', true);
        $this->createDummyPackage('labs/smart-agent', 'alex-kassel-labs/smart-agent');
        Workspace::sync();

        Process::fake([
            '*' => Process::result(output: 'Installed smart agent'),
        ]);

        // Install using short name 'smart-agent'
        $this->artisan('package:install', ['package' => 'smart-agent'])
            ->expectsOutputToContain('installed successfully')
            ->assertSuccessful();

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return str_contains($cmd, 'composer require alex-kassel-labs/smart-agent');
        });
    }

    public function test_package_install_fails_closed_when_package_not_in_workspace(): void
    {
        Workspace::add('packages', null, true);

        $this->artisan('package:install', ['package' => 'acme/non-existent'])
            ->expectsOutputToContain('was not found in any registered workspace')
            ->assertFailed();
    }

    public function test_package_install_allows_remote_flag(): void
    {
        Workspace::add('packages', null, true);

        Process::fake([
            '*' => Process::result(output: 'Installed remote'),
        ]);

        $this->artisan('package:install', [
            'package' => 'acme/remote-package',
            '--remote' => true,
        ])
            ->expectsOutputToContain('Installing from remote Composer repositories')
            ->assertSuccessful();

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return str_contains($cmd, 'composer require acme/remote-package');
        });
    }

    public function test_package_uninstall_removes_from_root_composer(): void
    {
        Workspace::add('packages', null, true);
        $this->createDummyPackage('packages/acme/my-lib', 'acme/my-lib');

        // Mark as installed in sandbox composer.json
        $composer = $this->getSandboxComposer();
        $composer['require']['acme/my-lib'] = 'dev-main';
        File::put(base_path('composer.json'), json_encode($composer));

        Process::fake([
            '*' => Process::result(output: 'Removed'),
        ]);

        $this->artisan('package:uninstall', ['package' => 'acme/my-lib'])
            ->expectsOutputToContain('uninstalled successfully')
            ->assertSuccessful();

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return str_contains($cmd, 'composer remove acme/my-lib') && ! str_contains($cmd, '--dev');
        });

        // Physical files preserved
        $this->assertDirectoryExists(base_path('packages/acme/my-lib'));
    }

    public function test_package_uninstall_auto_detects_require_dev_section(): void
    {
        Workspace::add('packages', null, true);
        $this->createDummyPackage('packages/acme/dev-tool', 'acme/dev-tool');

        // Mark as installed in sandbox composer.json under require-dev
        $composer = $this->getSandboxComposer();
        $composer['require-dev']['acme/dev-tool'] = 'dev-main';
        File::put(base_path('composer.json'), json_encode($composer));

        Process::fake([
            '*' => Process::result(output: 'Removed dev package'),
        ]);

        // Call without --dev option, should auto-detect and append --dev
        $this->artisan('package:uninstall', ['package' => 'acme/dev-tool'])
            ->expectsOutputToContain('uninstalled successfully')
            ->assertSuccessful();

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return str_contains($cmd, 'composer remove acme/dev-tool') && str_contains($cmd, '--dev');
        });
    }

    public function test_package_uninstall_fails_when_package_is_not_installed(): void
    {
        Workspace::add('packages', null, true);
        $this->createDummyPackage('packages/acme/uninstalled-lib', 'acme/uninstalled-lib');

        $this->artisan('package:uninstall', ['package' => 'acme/uninstalled-lib'])
            ->expectsOutputToContain('is not installed in root composer.json')
            ->assertFailed();
    }

    public function test_package_delete_prevents_directory_traversal(): void
    {
        Workspace::add('packages', null, true);

        // Attempting to delete with traversal pattern should fail cleanly
        $this->artisan('package:delete', [
            'package' => 'acme/../../dangerous',
            '--force' => true,
        ])
            ->assertFailed();
    }

    public function test_package_delete_removes_physical_directory_and_uninstalls(): void
    {
        Workspace::add('packages', null, true);
        $this->createDummyPackage('packages/acme/to-delete', 'acme/to-delete');
        Workspace::sync();

        // Mark as installed in composer.json
        $composer = $this->getSandboxComposer();
        $composer['require']['acme/to-delete'] = 'dev-main';
        File::put(base_path('composer.json'), json_encode($composer));

        Process::fake([
            '*' => Process::result(output: 'Removed'),
        ]);

        $this->artisan('package:delete', [
            'package' => 'acme/to-delete',
            '--force' => true,
        ])
            ->expectsOutputToContain('permanently deleted')
            ->assertSuccessful();

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return str_contains($cmd, 'composer remove acme/to-delete') && ! str_contains($cmd, '--dev');
        });

        $this->assertDirectoryDoesNotExist(base_path('packages/acme/to-delete'));
    }

    public function test_package_delete_removes_from_require_dev_section(): void
    {
        Workspace::add('packages', null, true);
        $this->createDummyPackage('packages/acme/dev-delete', 'acme/dev-delete');
        Workspace::sync();

        // Mark as installed under require-dev
        $composer = $this->getSandboxComposer();
        $composer['require-dev']['acme/dev-delete'] = 'dev-main';
        File::put(base_path('composer.json'), json_encode($composer));

        Process::fake([
            '*' => Process::result(output: 'Removed dev'),
        ]);

        $this->artisan('package:delete', [
            'package' => 'acme/dev-delete',
            '--force' => true,
        ])
            ->expectsOutputToContain('permanently deleted')
            ->assertSuccessful();

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return str_contains($cmd, 'composer remove acme/dev-delete') && str_contains($cmd, '--dev');
        });

        $this->assertDirectoryDoesNotExist(base_path('packages/acme/dev-delete'));
    }

    public function test_package_delete_cleans_up_empty_vendor_directory(): void
    {
        Workspace::add('packages', null, true);
        $this->createDummyPackage('packages/acme/lone-pkg', 'acme/lone-pkg');
        Workspace::sync();

        $this->assertDirectoryExists(base_path('packages/acme'));

        $this->artisan('package:delete', [
            'package' => 'acme/lone-pkg',
            '--force' => true,
        ])
            ->expectsOutputToContain('permanently deleted')
            ->assertSuccessful();

        // The package directory is gone
        $this->assertDirectoryDoesNotExist(base_path('packages/acme/lone-pkg'));
        // The empty vendor directory must also be automatically cleaned up
        $this->assertDirectoryDoesNotExist(base_path('packages/acme'));
        // But the workspace directory remains
        $this->assertDirectoryExists(base_path('packages'));
    }

    public function test_package_delete_blocks_dirty_git_repo_without_force(): void
    {
        Workspace::add('packages', null, true);
        $this->createDummyPackage('packages/acme/dirty-pkg', 'acme/dirty-pkg');
        File::ensureDirectoryExists(base_path('packages/acme/dirty-pkg/.git'));
        Workspace::sync();

        Process::fake(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
            if (str_contains($cmd, 'status')) {
                return Process::result("M dirty.php\n");
            }

            return Process::result('OK');
        });

        $this->artisan('package:delete', [
            'package' => 'acme/dirty-pkg',
        ])
            ->expectsOutputToContain('package working tree has uncommitted or untracked changes')
            ->assertFailed();

        $this->assertDirectoryExists(base_path('packages/acme/dirty-pkg'));
    }

    public function test_package_delete_blocks_unpushed_commits_without_force(): void
    {
        Workspace::add('packages', null, true);
        $this->createDummyPackage('packages/acme/unpushed-pkg', 'acme/unpushed-pkg');
        File::ensureDirectoryExists(base_path('packages/acme/unpushed-pkg/.git'));
        Workspace::sync();

        Process::fake(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
            if (str_contains($cmd, 'status')) {
                return Process::result('');
            }
            if (str_contains($cmd, 'rev-parse')) {
                return Process::result('origin/main');
            }
            if (str_contains($cmd, 'log')) {
                return Process::result("commit-hash feat: local commit\n");
            }

            return Process::result('OK');
        });

        $this->artisan('package:delete', [
            'package' => 'acme/unpushed-pkg',
        ])
            ->expectsOutputToContain('package contains local Git commits that have not been pushed to a remote repository')
            ->assertFailed();

        $this->assertDirectoryExists(base_path('packages/acme/unpushed-pkg'));
    }

    public function test_package_delete_bypasses_git_safety_with_force(): void
    {
        Workspace::add('packages', null, true);
        $this->createDummyPackage('packages/acme/dirty-pkg', 'acme/dirty-pkg');
        File::ensureDirectoryExists(base_path('packages/acme/dirty-pkg/.git'));
        Workspace::sync();

        Process::fake(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
            if (str_contains($cmd, 'status')) {
                return Process::result("M dirty.php\n");
            }

            return Process::result('OK');
        });

        $this->artisan('package:delete', [
            'package' => 'acme/dirty-pkg',
            '--force' => true,
        ])
            ->expectsOutputToContain('permanently deleted')
            ->assertSuccessful();

        $this->assertDirectoryDoesNotExist(base_path('packages/acme/dirty-pkg'));
    }

    public function test_package_alias_renames_directory_and_updates_manifest(): void
    {
        Workspace::add('app/Cores', 'alex-kassel', true);
        $this->createDummyPackage('app/Cores/scraper-core', 'alex-kassel/scraper-core');
        Workspace::sync();

        Process::fake([
            'composer*' => Process::result(output: 'dumped'),
        ]);

        $this->artisan('package:alias', [
            'package' => 'scraper-core',
            'alias' => 'Scraper',
        ])
            ->expectsOutputToContain('successfully aliased to [Scraper]')
            ->assertSuccessful();

        $this->assertDirectoryDoesNotExist(base_path('app/Cores/scraper-core'));
        $this->assertDirectoryExists(base_path('app/Cores/Scraper'));

        $manifest = Workspace::load();
        $packages = $manifest['workspaces']['app/Cores']['packages'];
        $this->assertSame([['name' => 'scraper-core', 'alias' => 'Scraper']], $packages);
    }

    public function test_package_alias_renames_existing_alias(): void
    {
        Workspace::add('app/Cores', 'alex-kassel', true);
        $this->createDummyPackage('app/Cores/scraper-core', 'alex-kassel/scraper-core');
        Workspace::sync();

        Process::fake([
            'composer*' => Process::result(output: 'dumped'),
        ]);

        // First alias: scraper-core -> ScraperEngine
        $this->artisan('package:alias', [
            'package' => 'scraper-core',
            'alias' => 'ScraperEngine',
        ])
            ->expectsOutputToContain('successfully aliased to [ScraperEngine]')
            ->assertSuccessful();

        $this->assertDirectoryExists(base_path('app/Cores/ScraperEngine'));

        // Second alias: rename ScraperEngine -> Scraper
        $this->artisan('package:alias', [
            'package' => 'ScraperEngine',
            'alias' => 'Scraper',
        ])
            ->expectsOutputToContain('successfully aliased to [Scraper]')
            ->assertSuccessful();

        $this->assertDirectoryExists(base_path('app/Cores/Scraper'));
        $this->assertDirectoryDoesNotExist(base_path('app/Cores/ScraperEngine'));
    }

    public function test_package_alias_fails_when_target_alias_directory_already_exists(): void
    {
        Workspace::add('app/Cores', 'alex-kassel', true);
        $this->createDummyPackage('app/Cores/pkg-one', 'alex-kassel/pkg-one');
        $this->createDummyPackage('app/Cores/SharedAlias', 'alex-kassel/pkg-two');
        Workspace::sync();

        $this->artisan('package:alias', [
            'package' => 'pkg-one',
            'alias' => 'SharedAlias',
        ])
            ->expectsOutputToContain('Cannot use alias [SharedAlias]: target directory [app/Cores/SharedAlias] already exists on disk.')
            ->expectsOutputToContain('Choose a different alias name or remove the conflicting directory.')
            ->assertFailed();
    }

    public function test_package_make_fails_when_alias_directory_already_exists(): void
    {
        Workspace::add('app/Cores', 'alex-kassel', true);
        $this->createDummyPackage('app/Cores/ExistingAlias', 'alex-kassel/existing-pkg');
        Workspace::sync();

        $this->artisan('package:make', [
            'package' => 'new-pkg',
            '--alias' => 'ExistingAlias',
            '--no-skills' => true,
        ])
            ->expectsOutputToContain('Cannot use alias [ExistingAlias]: target directory [app/Cores/ExistingAlias] already exists on disk.')
            ->assertFailed();

    }

    public function test_package_alias_prompts_for_alias_when_omitted(): void
    {
        Workspace::add('app/Cores', 'alex-kassel', true);
        $this->createDummyPackage('app/Cores/scraper-core', 'alex-kassel/scraper-core');
        Workspace::sync();

        Process::fake([
            'composer*' => Process::result(output: 'dumped'),
        ]);

        $this->artisan('package:alias', ['package' => 'scraper-core'])
            ->expectsQuestion('Please enter the new directory alias for [scraper-core] (e.g. MyPackage):', 'PromptedAlias')
            ->expectsOutputToContain('successfully aliased to [PromptedAlias]')
            ->assertSuccessful();

        $this->assertDirectoryExists(base_path('app/Cores/PromptedAlias'));
    }

    public function test_package_alias_warns_when_duplicate_alias_exists(): void
    {
        Workspace::add('app/Cores', 'alex-kassel');
        Workspace::add('packages', null, true);
        $this->createDummyPackage('app/Cores/scraper-core', 'alex-kassel/scraper-core');
        $this->createDummyPackage('packages/acme/Scraper', 'acme/scraper');
        Workspace::sync();

        Process::fake([
            'composer*' => Process::result(output: 'dumped'),
        ]);

        $this->artisan('package:alias', [
            'package' => 'scraper-core',
            'alias' => 'Scraper',
        ])
            ->expectsOutputToContain('successfully aliased to [Scraper]')
            ->expectsOutputToContain('The alias/name [Scraper] is also used by another package:')
            ->assertSuccessful();
    }

    public function test_package_alias_rejects_nested_workspace(): void
    {
        Workspace::add('packages', null, true);
        $this->createDummyPackage('packages/acme/my-lib', 'acme/my-lib');
        Workspace::sync();

        $this->artisan('package:alias', [
            'package' => 'my-lib',
            'alias' => 'MyLib',
        ])
            ->expectsOutputToContain('Aliases are only supported in flat (fixed-vendor) workspaces')
            ->assertFailed();
    }

    public function test_package_make_generates_all_stub_files(): void
    {
        Workspace::add('packages', null, true);

        $this->artisan('package:make', ['package' => 'acme/all-stubs-pkg'])
            ->assertSuccessful();

        $packageDir = base_path('packages/acme/all-stubs-pkg');
        $this->assertDirectoryExists($packageDir);
        $this->assertFileExists($packageDir.'/.gitattributes');
        $this->assertFileExists($packageDir.'/.gitignore');
        $this->assertFileExists($packageDir.'/phpunit.xml');
        $this->assertFileExists($packageDir.'/phpstan.neon');
        $this->assertFileExists($packageDir.'/CHANGELOG.md');
        $this->assertFileExists($packageDir.'/README.md');
        $this->assertFileExists($packageDir.'/tests/TestCase.php');
        $this->assertFileExists($packageDir.'/tests/bootstrap.php');
        $this->assertFileExists($packageDir.'/tests/Unit/.gitkeep');
    }

    public function test_package_make_replaces_placeholders_in_stubs(): void
    {
        Workspace::add('packages', null, true);

        $this->artisan('package:make', ['package' => 'acme/placeholder-pkg'])
            ->assertSuccessful();

        $packageDir = base_path('packages/acme/placeholder-pkg');

        $readme = File::get($packageDir.'/README.md');
        $this->assertStringContainsString('# PlaceholderPkg', $readme);
        $this->assertStringContainsString('composer require acme/placeholder-pkg', $readme);
        $this->assertStringContainsString('use Acme\PlaceholderPkg\PlaceholderPkgServiceProvider;', $readme);
        $this->assertStringContainsString('Copyright (c) '.date('Y').' acme.', $readme);
        $this->assertStringNotContainsString('{{', $readme);

        $changelog = File::get($packageDir.'/CHANGELOG.md');
        $this->assertStringContainsString('acme/placeholder-pkg', $changelog);
        $this->assertStringContainsString('## [Unreleased]', $changelog);
        $this->assertStringNotContainsString('{{', $changelog);

        $testCase = File::get($packageDir.'/tests/TestCase.php');
        $this->assertStringContainsString('namespace Acme\PlaceholderPkg\Tests;', $testCase);
        $this->assertStringContainsString('use Acme\PlaceholderPkg\PlaceholderPkgServiceProvider;', $testCase);
        $this->assertStringContainsString('PlaceholderPkgServiceProvider::class,', $testCase);
        $this->assertStringNotContainsString('{{', $testCase);

        $bootstrap = File::get($packageDir.'/tests/bootstrap.php');
        $this->assertStringContainsString("addPsr4('Acme\\\\PlaceholderPkg\\\\Tests\\\\'", $bootstrap);
        $this->assertStringNotContainsString('{{', $bootstrap);
    }

    public function test_package_make_custom_stubs_override_default_stubs(): void
    {
        Workspace::add('packages', null, true);

        $customStubsDir = base_path('stubs/workspace');
        File::ensureDirectoryExists($customStubsDir);
        File::put($customStubsDir.'/README.md.stub', "# Custom Header for {{ package }}\nBy {{ vendor }}.\n");

        $this->artisan('package:make', ['package' => 'acme/custom-stub-pkg'])
            ->assertSuccessful();

        $packageDir = base_path('packages/acme/custom-stub-pkg');
        $readme = File::get($packageDir.'/README.md');
        $this->assertSame("# Custom Header for custom-stub-pkg\nBy acme.\n", $readme);
    }

    public function test_package_make_initializes_git_repo_and_creates_commit_and_tag(): void
    {
        Workspace::add('packages', null, true);

        Process::fake([
            '*' => Process::result(output: 'ok'),
        ]);

        $this->artisan('package:make', [
            'package' => 'acme/git-pkg',
        ])
            ->expectsOutputToContain('Git repository initialized with initial commit and tag v0.0.1.')
            ->assertSuccessful();

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

            return str_contains($cmd, 'git add');
        });

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

            return str_contains($cmd, 'git commit') && str_contains($cmd, 'acme/git-pkg');
        });

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

            return str_contains($cmd, 'git tag') && str_contains($cmd, 'v0.0.1');
        });
    }

    public function test_deleting_last_package_in_flat_workspace_preserves_workspace_directory(): void
    {
        Workspace::add('labs', 'alex-kassel', true);
        $this->createDummyPackage('labs/my-pkg', 'alex-kassel/my-pkg');
        Workspace::sync();

        $this->artisan('package:delete', ['package' => 'my-pkg', '--force' => true])
            ->assertSuccessful();

        $this->assertDirectoryExists(base_path('labs'));
    }

    public function test_deleting_package_in_nested_workspace_cleans_empty_vendor_dir(): void
    {
        Workspace::add('packages');
        $this->createDummyPackage('packages/acme/lone-pkg', 'acme/lone-pkg');
        Workspace::sync();

        $this->artisan('package:delete', ['package' => 'acme/lone-pkg', '--force' => true])
            ->assertSuccessful();

        $this->assertDirectoryDoesNotExist(base_path('packages/acme'));
        $this->assertDirectoryExists(base_path('packages'));
    }

    public function test_scaffold_rolls_back_directory_when_git_init_fails(): void
    {
        Workspace::add('packages', null, true);

        Process::fake(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
            if (str_contains($cmd, 'git init')) {
                return Process::result('', 'fatal: cannot create', 128);
            }

            return Process::result('ok');
        });

        $scaffolder = app(PackageScaffolder::class);

        try {
            $scaffolder->scaffold('packages', 'acme/broken-pkg');
            $this->fail('Expected exception');
        } catch (WorkspaceException $e) {
        }

        $this->assertDirectoryDoesNotExist(base_path('packages/acme/broken-pkg'),
            'Directory must be cleaned up after failed git init');

        $manifest = Workspace::load();
        $names = array_map(
            fn ($p) => is_array($p) ? $p['name'] : $p,
            $manifest['workspaces']['packages']['packages'] ?? []
        );
        $this->assertNotContains('acme/broken-pkg', $names,
            'Package must not remain in manifest after rollback');
    }

    public function test_package_make_with_minimal_archetype(): void
    {
        Workspace::add('packages', null, true);

        Process::fake([
            '*' => Process::result('ok'),
        ]);

        $this->artisan('package:make', [
            'package' => 'acme/mini-pkg',
            '--type' => 'minimal',
        ])->assertSuccessful();

        $dir = base_path('packages/acme/mini-pkg');
        $this->assertFileExists("{$dir}/composer.json");
        $this->assertFileExists("{$dir}/src/MiniPkgServiceProvider.php");
        $this->assertFileDoesNotExist("{$dir}/LICENSE");
        $this->assertFileDoesNotExist("{$dir}/phpunit.xml");
        $this->assertFileDoesNotExist("{$dir}/.github/workflows/run-tests.yml");
    }

    public function test_package_make_with_pest_archetype(): void
    {
        Workspace::add('packages', null, true);

        Process::fake([
            '*' => Process::result('ok'),
        ]);

        $this->artisan('package:make', [
            'package' => 'acme/pest-pkg',
            '--archetype' => 'pest',
        ])->assertSuccessful();

        $dir = base_path('packages/acme/pest-pkg');
        $this->assertFileExists("{$dir}/tests/Pest.php");
        $this->assertFileExists("{$dir}/tests/Feature/ExampleTest.php");
        $this->assertFileDoesNotExist("{$dir}/tests/Unit/ExampleTest.php");
    }

    public function test_package_make_with_ddd_module_archetype(): void
    {
        Workspace::add('packages', null, true);

        Process::fake([
            '*' => Process::result('ok'),
        ]);

        $this->artisan('package:make', [
            'package' => 'acme/ddd-pkg',
            '--type' => 'ddd-module',
        ])->assertSuccessful();

        $dir = base_path('packages/acme/ddd-pkg');
        $this->assertDirectoryExists("{$dir}/src/Domain");
        $this->assertDirectoryExists("{$dir}/src/Actions");
        $this->assertDirectoryExists("{$dir}/src/DTOs");
        $this->assertDirectoryExists("{$dir}/src/Contracts");
    }
}
