<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class PackageCheckCommandTest extends TestCase
{
    /**
     * Create mock host vendor/bin binaries in sandbox.
     */
    protected function createMockBinaries(array $binaries = ['pint', 'phpstan', 'phpunit']): void
    {
        $binDir = base_path('vendor/bin');
        File::ensureDirectoryExists($binDir);

        foreach ($binaries as $bin) {
            if (PHP_OS_FAMILY === 'Windows') {
                File::put($binDir.DIRECTORY_SEPARATOR.$bin.'.bat', "@echo off\necho OK\n");
            } else {
                File::put($binDir.DIRECTORY_SEPARATOR.$bin, "#!/bin/sh\necho OK\n");
                chmod($binDir.DIRECTORY_SEPARATOR.$bin, 0755);
            }
        }
    }

    /**
     * Helper to scaffold a full test package in sandbox.
     */
    protected function scaffoldTestPackage(string $relativeDir, string $packageName): string
    {
        $absDir = base_path($relativeDir);
        File::ensureDirectoryExists($absDir.'/src');
        File::ensureDirectoryExists($absDir.'/tests');

        File::put($absDir.'/composer.json', json_encode([
            'name' => $packageName,
            'type' => 'library',
            'autoload' => ['psr-4' => ['Acme\\Test\\' => 'src/']],
        ], JSON_PRETTY_PRINT));

        File::put($absDir.'/phpunit.xml', '<phpunit bootstrap="tests/bootstrap.php"></phpunit>');
        File::put($absDir.'/phpstan.neon', "parameters:\n    level: 8\n");
        File::put($absDir.'/src/TestClass.php', "<?php\n\nnamespace Acme\\Test;\n\nclass TestClass {}\n");

        return $absDir;
    }

    public function test_package_check_single_package_runs_all_checks(): void
    {
        Workspace::add('packages', null, true);
        $this->scaffoldTestPackage('packages/acme/my-pkg', 'acme/my-pkg');
        $this->createMockBinaries();

        Process::fake([
            '*' => Process::result(output: 'OK'),
        ]);

        $this->artisan('package:check', ['name' => 'acme/my-pkg'])
            ->expectsOutputToContain('Verifying package [acme/my-pkg]')
            ->expectsOutputToContain('COMPOSER')
            ->expectsOutputToContain('PINT')
            ->expectsOutputToContain('PHPSTAN')
            ->expectsOutputToContain('TESTS')
            ->expectsOutputToContain('Verification passed for package [acme/my-pkg]')
            ->assertSuccessful();

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

            return str_contains($cmd, 'composer') && str_contains($cmd, 'validate');
        });

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

            return str_contains($cmd, 'pint');
        });

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

            return str_contains($cmd, 'phpstan');
        });

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

            return str_contains($cmd, 'phpunit');
        });
    }

    public function test_package_check_with_only_flag_runs_subset_of_checks(): void
    {
        Workspace::add('packages', null, true);
        $this->scaffoldTestPackage('packages/acme/my-pkg', 'acme/my-pkg');
        $this->createMockBinaries();

        Process::fake([
            '*' => Process::result(output: 'OK'),
        ]);

        $this->artisan('package:check', [
            'name' => 'acme/my-pkg',
            '--only' => 'pint',
        ])
            ->expectsOutputToContain('PINT')
            ->doesntExpectOutputToContain('COMPOSER')
            ->doesntExpectOutputToContain('PHPSTAN')
            ->doesntExpectOutputToContain('TESTS')
            ->assertSuccessful();

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

            return str_contains($cmd, 'pint');
        });

        Process::assertNotRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

            return str_contains($cmd, 'validate');
        });
    }

    public function test_package_check_with_fix_flag_runs_pint_without_test_flag(): void
    {
        Workspace::add('packages', null, true);
        $this->scaffoldTestPackage('packages/acme/my-pkg', 'acme/my-pkg');
        $this->createMockBinaries();

        Process::fake([
            '*' => Process::result(output: 'OK'),
        ]);

        $this->artisan('package:check', [
            'name' => 'acme/my-pkg',
            '--only' => 'pint',
            '--fix' => true,
        ])->assertSuccessful();

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

            return str_contains($cmd, 'pint') && ! str_contains($cmd, '--test');
        });
    }

    public function test_package_check_missing_binary_marks_check_as_skipped(): void
    {
        Workspace::add('packages', null, true);
        $this->scaffoldTestPackage('packages/acme/my-pkg', 'acme/my-pkg');

        // Do NOT create mock binaries in vendor/bin -> Pint, PHPStan, PHPUnit missing
        Process::fake([
            '*' => Process::result(output: 'OK'),
        ]);

        $this->artisan('package:check', ['name' => 'acme/my-pkg'])
            ->expectsOutputToContain('SKIP')
            ->assertSuccessful();
    }

    public function test_package_check_all_discovers_all_packages(): void
    {
        Workspace::add('packages', null, true);
        $this->scaffoldTestPackage('packages/acme/pkg-a', 'acme/pkg-a');
        $this->scaffoldTestPackage('packages/acme/pkg-b', 'acme/pkg-b');
        $this->createMockBinaries();

        Process::fake([
            '*' => Process::result(output: 'OK'),
        ]);

        $this->artisan('package:check', ['--all' => true])
            ->expectsOutputToContain('Verifying 2 package(s) across workspaces')
            ->expectsOutputToContain('acme/pkg-a')
            ->expectsOutputToContain('acme/pkg-b')
            ->assertSuccessful();
    }

    public function test_package_check_without_arguments_shows_error_and_hint(): void
    {
        Workspace::add('packages', null, true);

        $this->artisan('package:check')
            ->expectsOutputToContain('Please specify a package name or use the [--all] flag')
            ->assertFailed();
    }

    public function test_package_check_nonexistent_package_fails(): void
    {
        Workspace::add('packages', null, true);

        $this->artisan('package:check', ['name' => 'nonexistent/pkg'])
            ->expectsOutputToContain('Package [nonexistent/pkg] not found')
            ->assertFailed();
    }
}
