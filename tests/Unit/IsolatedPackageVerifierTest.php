<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Unit;

use AlexKassel\WorkspaceDevelopmentToolkit\Services\FilesystemHelper;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\IsolatedPackageVerifier;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\PackageResolver;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class IsolatedPackageVerifierTest extends TestCase
{
    public function test_isolated_verification_rejects_path_repository_in_composer_json(): void
    {
        $pkgDir = $this->tempDir.'/packages/acme/path-repo-pkg';
        File::ensureDirectoryExists($pkgDir);

        File::put($pkgDir.'/composer.json', json_encode([
            'name' => 'acme/path-repo-pkg',
            'repositories' => [
                ['type' => 'path', 'url' => '../../other'],
            ],
        ], JSON_PRETTY_PRINT));

        $verifier = app(IsolatedPackageVerifier::class);
        $result = $verifier->verify($pkgDir);

        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('rejects path repositories', $result->output);
    }

    public function test_isolated_verification_rejects_package_missing_phpunit_xml(): void
    {
        $pkgDir = $this->tempDir.'/packages/acme/no-tests-pkg';
        File::ensureDirectoryExists($pkgDir);

        File::put($pkgDir.'/composer.json', json_encode([
            'name' => 'acme/no-tests-pkg',
        ], JSON_PRETTY_PRINT));

        $verifier = app(IsolatedPackageVerifier::class);
        $result = $verifier->verify($pkgDir);

        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('requires phpunit.xml', $result->output);
    }

    public function test_isolated_verification_injects_environment_variables(): void
    {
        $pkgDir = $this->tempDir.'/packages/acme/env-test-pkg';
        File::ensureDirectoryExists($pkgDir.'/src');
        File::ensureDirectoryExists($pkgDir.'/tests');

        File::put($pkgDir.'/composer.json', json_encode([
            'name' => 'acme/env-test-pkg',
        ], JSON_PRETTY_PRINT));
        File::put($pkgDir.'/phpunit.xml', '<phpunit></phpunit>');

        Process::fake([
            '*' => function ($process) {
                // When composer install runs, simulate vendor/bin/phpunit creation in the project
                $cwd = $process->path;
                $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

                if (str_contains($cmd, 'git ls-files')) {
                    return Process::result(output: "composer.json\0phpunit.xml\0");
                }

                if (is_string($cwd) && str_contains($cmd, 'composer install')) {
                    $binDir = $cwd.'/vendor/bin';
                    File::ensureDirectoryExists($binDir);
                    if (PHP_OS_FAMILY === 'Windows') {
                        File::put($binDir.'/phpunit.bat', "@echo off\necho OK\n");
                    } else {
                        File::put($binDir.'/phpunit', "#!/bin/sh\necho OK\n");
                    }
                }

                return Process::result(output: 'OK');
            },
        ]);

        $verifier = app(IsolatedPackageVerifier::class);
        $result = $verifier->verify($pkgDir);

        $this->assertSame('passed', $result->status, $result->output);

        Process::assertRan(function ($process) {
            $env = $process->environment;

            return isset($env['APP_ENV'])
                && $env['APP_ENV'] === 'testing'
                && isset($env['COMPOSER_VENDOR_DIR'])
                && isset($env['COMPOSER_HOME']);
        });
    }

    public function test_isolated_verification_calls_cleanup_in_finally(): void
    {
        $pkgDir = $this->tempDir.'/packages/acme/cleanup-pkg';
        File::ensureDirectoryExists($pkgDir);

        File::put($pkgDir.'/composer.json', json_encode([
            'name' => 'acme/cleanup-pkg',
            'repositories' => [
                ['type' => 'path', 'url' => '../../other'], // Will fail
            ],
        ]));

        $mockFs = $this->createMock(FilesystemHelper::class);
        $mockFs->expects($this->once())
            ->method('deleteDirectoryRecursively')
            ->willReturn(true);

        $verifier = new IsolatedPackageVerifier($mockFs);
        $result = $verifier->verify($pkgDir);

        $this->assertSame('failed', $result->status);
    }

    public function test_isolated_export_copies_files_and_ignores_sensitive_directories(): void
    {
        $source = $this->tempDir.'/packages/acme/source-pkg';
        $dest = $this->tempDir.'/export-target';

        File::ensureDirectoryExists($source.'/src');
        File::ensureDirectoryExists($source.'/tests');
        File::ensureDirectoryExists($source.'/vendor/autoload');
        File::ensureDirectoryExists($dest);

        File::put($source.'/composer.json', '{"name":"acme/source-pkg"}');
        File::put($source.'/src/ClassA.php', '<?php class ClassA {}');
        File::put($source.'/tests/ClassATest.php', '<?php class ClassATest {}');
        File::put($source.'/.env', 'SECRET=xyz');
        File::put($source.'/composer.lock', '{}');
        File::put($source.'/vendor/autoload/file.php', '<?php');

        $verifier = app(IsolatedPackageVerifier::class);
        $verifier->export($source, $dest);

        $this->assertFileExists($dest.'/composer.json');
        $this->assertFileExists($dest.'/src/ClassA.php');
        $this->assertFileExists($dest.'/tests/ClassATest.php');
        $this->assertFileDoesNotExist($dest.'/.env');
        $this->assertFileDoesNotExist($dest.'/composer.lock');
        $this->assertFileDoesNotExist($dest.'/vendor/autoload/file.php');
    }

    public function test_isolated_verification_detects_workspace_dependency_and_fails_without_flag(): void
    {
        $siblingDir = $this->tempDir.'/packages/acme/sibling-core';
        $targetDir = $this->tempDir.'/packages/acme/consumer-pkg';
        File::ensureDirectoryExists($siblingDir);
        File::ensureDirectoryExists($targetDir);

        File::put($siblingDir.'/composer.json', json_encode(['name' => 'acme/sibling-core']));
        File::put($targetDir.'/composer.json', json_encode([
            'name' => 'acme/consumer-pkg',
            'require' => [
                'acme/sibling-core' => '^1.0',
            ],
        ], JSON_PRETTY_PRINT));
        File::put($targetDir.'/phpunit.xml', '<phpunit></phpunit>');

        $mockResolver = $this->createMock(PackageResolver::class);
        $mockResolver->method('findPackagePath')
            ->with('acme/sibling-core')
            ->willReturn($siblingDir);

        $verifier = new IsolatedPackageVerifier(app(FilesystemHelper::class), $mockResolver);
        $result = $verifier->verify($targetDir, withWorkspaceDeps: false);

        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('[WORKSPACE DEPENDENCY DETECTED]', $result->output);
        $this->assertStringContainsString('--with-workspace-deps', $result->output);
    }

    public function test_isolated_verification_links_workspace_dependency_when_flag_provided(): void
    {
        $siblingDir = $this->tempDir.'/packages/acme/sibling-core';
        $targetDir = $this->tempDir.'/packages/acme/consumer-pkg';
        File::ensureDirectoryExists($siblingDir);
        File::ensureDirectoryExists($targetDir);

        File::put($siblingDir.'/composer.json', json_encode(['name' => 'acme/sibling-core']));
        File::put($targetDir.'/composer.json', json_encode([
            'name' => 'acme/consumer-pkg',
            'require' => [
                'acme/sibling-core' => '^1.0',
            ],
        ], JSON_PRETTY_PRINT));
        File::put($targetDir.'/phpunit.xml', '<phpunit></phpunit>');

        $mockResolver = $this->createMock(PackageResolver::class);
        $mockResolver->method('findPackagePath')
            ->with('acme/sibling-core')
            ->willReturn($siblingDir);

        Process::fake([
            '*' => function ($process) {
                $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
                $cwd = $process->path;

                if (str_contains($cmd, 'git ls-files')) {
                    return Process::result(output: "composer.json\0phpunit.xml\0");
                }

                if (is_string($cwd) && str_contains($cmd, 'composer install')) {
                    $binDir = $cwd.'/vendor/bin';
                    File::ensureDirectoryExists($binDir);
                    File::put($binDir.'/phpunit', "#!/bin/sh\necho OK\n");
                }

                return Process::result(output: 'OK');
            },
        ]);

        $verifier = new IsolatedPackageVerifier(app(FilesystemHelper::class), $mockResolver);
        $result = $verifier->verify($targetDir, withWorkspaceDeps: true);

        $this->assertSame('passed', $result->status, $result->output);
    }
}
