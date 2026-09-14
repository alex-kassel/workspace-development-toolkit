<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

class WorkspaceCiMatrixCommandTest extends TestCase
{
    public function test_workspace_ci_matrix_outputs_json(): void
    {
        Workspace::add('packages', null, true);

        $coreDir = $this->tempDir.'/packages/acme/core';
        File::ensureDirectoryExists($coreDir);
        File::put($coreDir.'/composer.json', json_encode([
            'name' => 'acme/core',
            'require' => [
                'php' => '^8.3',
                'illuminate/support' => '^13.0',
            ],
        ]));

        Workspace::sync();

        $this->artisan('workspace:ci-matrix', ['--json' => true])
            ->assertSuccessful();
    }

    public function test_workspace_ci_matrix_dry_run_prints_yaml(): void
    {
        Workspace::add('packages', null, true);

        $coreDir = $this->tempDir.'/packages/acme/core';
        File::ensureDirectoryExists($coreDir);
        File::put($coreDir.'/composer.json', json_encode([
            'name' => 'acme/core',
            'require' => [
                'php' => '^8.3',
                'illuminate/support' => '^12.0|^13.0',
            ],
        ]));

        Workspace::sync();

        $this->artisan('workspace:ci-matrix', ['--dry-run' => true])
            ->expectsOutputToContain('packages-ci')
            ->expectsOutputToContain('acme/core')
            ->assertSuccessful();

        $defaultWorkflowFile = $this->tempDir.'/.github/workflows/packages-matrix.yml';
        $this->assertFileDoesNotExist($defaultWorkflowFile);
    }

    public function test_workspace_ci_matrix_generates_workflow_file_and_handles_force(): void
    {
        Workspace::add('packages', null, true);

        $coreDir = $this->tempDir.'/packages/acme/core';
        File::ensureDirectoryExists($coreDir);
        File::put($coreDir.'/composer.json', json_encode([
            'name' => 'acme/core',
            'require' => [
                'php' => '^8.2',
                'illuminate/support' => '^11.0|^12.0',
            ],
        ]));

        Workspace::sync();

        $targetRelative = '.github/workflows/packages-matrix.yml';
        $targetFile = $this->tempDir.'/'.$targetRelative;

        $this->artisan('workspace:ci-matrix', ['--file' => $targetRelative])
            ->expectsOutputToContain('test matrix generated for workspace')
            ->assertSuccessful();

        $this->assertFileExists($targetFile);
        $content = File::get($targetFile);
        $parsed = Yaml::parse($content);
        $this->assertSame('packages-ci', $parsed['name']);

        // Re-run without force warns
        $this->artisan('workspace:ci-matrix', ['--file' => $targetRelative])
            ->expectsOutputToContain('already exists')
            ->assertSuccessful();

        // Re-run with force overwrites
        $this->artisan('workspace:ci-matrix', ['--file' => $targetRelative, '--force' => true])
            ->expectsOutputToContain('test matrix generated for workspace')
            ->assertSuccessful();
    }

    public function test_workspace_ci_matrix_with_only_changed_filter(): void
    {
        Workspace::add('packages', null, true);

        $coreDir = $this->tempDir.'/packages/acme/core';
        File::ensureDirectoryExists($coreDir);
        File::put($coreDir.'/composer.json', json_encode([
            'name' => 'acme/core',
            'require' => [
                'php' => '^8.3',
                'illuminate/support' => '^13.0',
            ],
        ]));

        $otherDir = $this->tempDir.'/packages/acme/other';
        File::ensureDirectoryExists($otherDir);
        File::put($otherDir.'/composer.json', json_encode([
            'name' => 'acme/other',
            'require' => [
                'php' => '^8.2',
                'illuminate/support' => '^11.0',
            ],
        ]));

        Workspace::sync();

        Process::fake([
            '*diff*' => Process::result("packages/acme/core/src/Core.php\n"),
            '*status*' => Process::result(''),
        ]);

        $this->artisan('workspace:ci-matrix', ['--json' => true, '--only-changed' => true])
            ->expectsOutputToContain('acme/core')
            ->doesntExpectOutputToContain('acme/other')
            ->assertSuccessful();
    }
}
