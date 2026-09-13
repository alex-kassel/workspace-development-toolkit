<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;

class PackageWorkflowCommandTest extends TestCase
{
    public function test_package_workflow_generates_ci_workflow(): void
    {
        Workspace::add('packages');

        $coreDir = $this->tempDir.'/packages/acme/workflow-pkg';
        File::ensureDirectoryExists($coreDir);
        File::put($coreDir.'/composer.json', json_encode(['name' => 'acme/workflow-pkg']));

        Workspace::sync();

        $this->artisan('package:workflow', ['package' => 'acme/workflow-pkg'])
            ->expectsOutputToContain('test matrix generated')
            ->assertSuccessful();

        $workflowFile = $coreDir.'/.github/workflows/run-tests.yml';
        $this->assertFileExists($workflowFile);
        $content = File::get($workflowFile);
        $this->assertStringContainsString('run-tests', $content);
        $this->assertStringContainsString('matrix', $content);

        // Re-running without force shows warning
        $this->artisan('package:workflow', ['package' => 'acme/workflow-pkg'])
            ->expectsOutputToContain('already exists')
            ->assertSuccessful();

        // Re-running with force succeeds
        $this->artisan('package:workflow', ['package' => 'acme/workflow-pkg', '--force' => true])
            ->expectsOutputToContain('test matrix generated')
            ->assertSuccessful();
    }
}
