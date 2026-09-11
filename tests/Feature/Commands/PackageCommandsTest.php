<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature\Commands;

require_once dirname(__DIR__, 2).'/TestCase.php';

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

        // Physical files preserved
        $this->assertDirectoryExists(base_path('packages/acme/my-lib'));
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

        $this->assertDirectoryDoesNotExist(base_path('packages/acme/to-delete'));
    }
}
