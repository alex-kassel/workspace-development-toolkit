<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class PackageCommandsTest extends TestCase
{
    public function test_package_make_scaffolds_multi_vendor_package(): void
    {
        Workspace::add('packages', null, true);

        $this->artisan('package:make', ['name' => 'acme/billing-module'])
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
        $this->assertStringContainsString('^13.0', $composerContent['require']['illuminate/support']);

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

        $this->artisan('package:make', ['name' => 'acme/existing-pkg'])
            ->assertSuccessful();

        // Second attempt must fail cleanly
        $this->artisan('package:make', ['name' => 'acme/existing-pkg'])
            ->expectsOutputToContain('already exists')
            ->assertFailed();
    }

    public function test_package_make_rejects_dev_option_without_install_option(): void
    {
        Workspace::add('packages', null, true);

        $this->artisan('package:make', [
            'name' => 'acme/dev-only-pkg',
            '--dev' => true,
        ])
            ->expectsOutputToContain('The [--dev] option can only be used in combination with [--install]')
            ->assertFailed();
    }

    public function test_package_make_scaffolds_flat_fixed_vendor_package(): void
    {
        Workspace::add('labs', 'alex-kassel-labs', true);

        $this->artisan('package:make', ['name' => 'demo-bot'])
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

    public function test_package_make_rejects_vendor_mismatch_in_fixed_vendor_workspace(): void
    {
        Workspace::add('labs', 'alex-kassel-labs', true);

        $this->artisan('package:make', ['name' => 'wrong-vendor/my-tool'])
            ->expectsOutputToContain('has a fixed vendor [alex-kassel-labs], but [wrong-vendor] was provided')
            ->assertFailed();
    }

    public function test_package_make_fails_when_no_default_workspace_is_configured(): void
    {
        // Sandbox has no workspaces registered
        $this->artisan('package:make', ['name' => 'acme/test-pkg'])
            ->expectsOutputToContain('No default workspace is currently configured')
            ->assertFailed();
    }

    public function test_package_make_rejects_invalid_composer_name(): void
    {
        Workspace::add('packages', null, true);

        $this->artisan('package:make', ['name' => 'invalid vendor/package!'])
            ->expectsOutputToContain('Composer vendor and package names must contain only lowercase letters')
            ->assertFailed();
    }

    public function test_package_make_preserves_dots_and_underscores_in_identity(): void
    {
        Workspace::add('packages', null, true);

        $this->artisan('package:make', ['name' => 'vendor_a/pkg.b'])
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
            'name' => 'acme/installable-pkg',
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
            'name' => 'acme/dev-pkg',
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
            '*' => Process::result(exitCode: 1, errorOutput: 'Dependency conflict'),
        ]);

        $this->artisan('package:make', [
            'name' => 'acme/failed-install-pkg',
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

        $this->artisan('package:install', ['name' => 'acme/my-lib'])
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
            'name' => 'acme/my-dev-lib',
            '--dev' => true,
        ])
            ->expectsOutputToContain('installed successfully')
            ->assertSuccessful();

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return str_contains($cmd, 'composer require acme/my-dev-lib') && str_contains($cmd, '--dev');
        });
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
        $this->artisan('package:install', ['name' => 'smart-agent'])
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

        $this->artisan('package:install', ['name' => 'acme/non-existent'])
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
            'name' => 'acme/remote-package',
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

        $this->artisan('package:uninstall', ['name' => 'acme/my-lib'])
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
        $this->artisan('package:uninstall', ['name' => 'acme/dev-tool'])
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

        $this->artisan('package:uninstall', ['name' => 'acme/uninstalled-lib'])
            ->expectsOutputToContain('is not installed in root composer.json')
            ->assertFailed();
    }

    public function test_package_delete_prevents_directory_traversal(): void
    {
        Workspace::add('packages', null, true);

        // Attempting to delete with traversal pattern should fail cleanly
        $this->artisan('package:delete', [
            'name' => 'acme/../../dangerous',
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
            'name' => 'acme/to-delete',
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
            'name' => 'acme/dev-delete',
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
            'name' => 'acme/lone-pkg',
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

    public function test_package_alias_renames_directory_and_updates_manifest(): void
    {
        Workspace::add('app/Cores', 'alex-kassel', true);
        $this->createDummyPackage('app/Cores/scraper-core', 'alex-kassel/scraper-core');
        Workspace::sync();

        Process::fake([
            '*' => Process::result(output: 'dumped'),
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

    public function test_package_alias_with_as_and_alias_options(): void
    {
        Workspace::add('app/Cores', 'alex-kassel', true);
        $this->createDummyPackage('app/Cores/scraper-core', 'alex-kassel/scraper-core');
        Workspace::sync();

        Process::fake([
            '*' => Process::result(output: 'dumped'),
        ]);

        $this->artisan('package:alias', [
            'package' => 'scraper-core',
            '--as' => 'ScraperEngine',
        ])
            ->expectsOutputToContain('successfully aliased to [ScraperEngine]')
            ->assertSuccessful();

        $this->assertDirectoryExists(base_path('app/Cores/ScraperEngine'));

        // Test with --alias option
        $this->artisan('package:alias', [
            'package' => 'ScraperEngine',
            '--alias' => 'Scraper',
        ])
            ->expectsOutputToContain('successfully aliased to [Scraper]')
            ->assertSuccessful();

        $this->assertDirectoryExists(base_path('app/Cores/Scraper'));
    }

    public function test_package_alias_warns_when_duplicate_alias_exists(): void
    {
        Workspace::add('app/Cores', 'alex-kassel');
        Workspace::add('packages', null, true);
        $this->createDummyPackage('app/Cores/scraper-core', 'alex-kassel/scraper-core');
        $this->createDummyPackage('packages/acme/Scraper', 'acme/scraper');
        Workspace::sync();

        Process::fake([
            '*' => Process::result(output: 'dumped'),
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
}
