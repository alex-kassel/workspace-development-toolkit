<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Unit\Processors;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\WorkspaceContext;
use AlexKassel\WorkspaceDevelopmentToolkit\Processors\Workspace\AgentsGuidelineWorkspaceProcessor;
use AlexKassel\WorkspaceDevelopmentToolkit\Processors\Workspace\BoostConfigWorkspaceProcessor;
use AlexKassel\WorkspaceDevelopmentToolkit\Processors\Workspace\CleanupArtifactsWorkspaceProcessor;
use AlexKassel\WorkspaceDevelopmentToolkit\Processors\Workspace\CustomHookWorkspaceProcessor;
use AlexKassel\WorkspaceDevelopmentToolkit\Processors\Workspace\RunnerWorkspaceProcessor;
use AlexKassel\WorkspaceDevelopmentToolkit\Processors\Workspace\SetupManifestWorkspaceProcessor;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;

class WorkspaceProcessorsTest extends TestCase
{
    protected string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = base_path('test_proc_env');
        File::ensureDirectoryExists($this->testDir);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->testDir)) {
            File::deleteDirectory($this->testDir);
        }
        parent::tearDown();
    }

    public function test_setup_manifest_workspace_processor(): void
    {
        $processor = app(SetupManifestWorkspaceProcessor::class);
        $context = new WorkspaceContext(rootPath: $this->testDir, workspaces: ['custom_pkgs'], defaultWorkspace: 'custom_pkgs');

        $processor->process($context);

        $manifestFile = $this->testDir.'/workspace.json';
        $this->assertFileExists($manifestFile);
        $this->assertDirectoryExists($this->testDir.'/custom_pkgs');

        $data = json_decode((string) File::get($manifestFile), true);
        $this->assertArrayHasKey('custom_pkgs', $data['workspaces']);
        $this->assertSame('custom_pkgs', $data['default']);

        // Running again without force should skip
        $context2 = new WorkspaceContext(rootPath: $this->testDir, workspaces: ['custom_pkgs'], defaultWorkspace: 'custom_pkgs');
        $processor->process($context2);
        $this->assertSame('skipped', $context2->steps[0]['status']);
    }

    public function test_agents_guideline_workspace_processor_standalone(): void
    {
        $processor = app(AgentsGuidelineWorkspaceProcessor::class);
        $context = new WorkspaceContext(rootPath: $this->testDir);

        $processor->process($context);

        $agentsFile = $this->testDir.'/AGENTS.md';
        $this->assertFileExists($agentsFile);
        $this->assertFalse(is_link($agentsFile));
        $this->assertStringContainsString('Project Rules & Guidelines', (string) File::get($agentsFile));
    }

    public function test_agents_guideline_workspace_processor_self_symlink(): void
    {
        $pkgPath = 'packages/alex-kassel/test-toolkit';
        $pkgFull = $this->testDir.'/'.$pkgPath;
        File::ensureDirectoryExists($pkgFull.'/stubs');
        File::put($pkgFull.'/stubs/AGENTS.md.stub', '# Test Toolkit Rules');

        $processor = app(AgentsGuidelineWorkspaceProcessor::class);
        $context = new WorkspaceContext(
            rootPath: $this->testDir,
            isSelf: true,
            selfPackagePath: $pkgPath,
        );

        $processor->process($context);

        $agentsFile = $this->testDir.'/AGENTS.md';
        $this->assertTrue(is_link($agentsFile));
        $this->assertSame('# Test Toolkit Rules', (string) File::get($agentsFile));
    }

    public function test_boost_config_workspace_processor(): void
    {
        $processor = app(BoostConfigWorkspaceProcessor::class);
        $context = new WorkspaceContext(rootPath: $this->testDir);

        $processor->process($context);

        $boostFile = $this->testDir.'/boost.json';
        $this->assertFileExists($boostFile);

        $content = json_decode((string) File::get($boostFile), true);
        $this->assertFalse($content['guidelines']);
        $this->assertContains('alex-kassel/workspace-development-toolkit', $content['packages']);

        // Test updating existing with guidelines: true
        File::put($boostFile, json_encode(['guidelines' => true, 'packages' => ['other/pkg']]));
        $context2 = new WorkspaceContext(rootPath: $this->testDir);
        $processor->process($context2);

        $updated = json_decode((string) File::get($boostFile), true);
        $this->assertFalse($updated['guidelines']);
        $this->assertContains('alex-kassel/workspace-development-toolkit', $updated['packages']);
        $this->assertContains('other/pkg', $updated['packages']);
    }

    public function test_runner_workspace_processor(): void
    {
        $processor = app(RunnerWorkspaceProcessor::class);
        $context = new WorkspaceContext(rootPath: $this->testDir);

        $processor->process($context);

        $runner = $this->testDir.'/workspace';
        $this->assertFileExists($runner);
        $this->assertStringContainsString('#!/usr/bin/env php', (string) File::get($runner));
    }

    public function test_cleanup_artifacts_workspace_processor(): void
    {
        File::put($this->testDir.'/CLOUD.md', '# Cloud rules');

        $processor = app(CleanupArtifactsWorkspaceProcessor::class);
        $context = new WorkspaceContext(rootPath: $this->testDir);

        $processor->process($context);

        $this->assertFileDoesNotExist($this->testDir.'/CLOUD.md');
        $this->assertSame('cleaned', $context->steps[0]['status']);

        // Test skip cleanup
        File::put($this->testDir.'/CLOUD.md', '# Cloud rules again');
        $contextSkip = new WorkspaceContext(rootPath: $this->testDir, skipCleanup: true);
        $processor->process($contextSkip);

        $this->assertFileExists($this->testDir.'/CLOUD.md');
        $this->assertSame('skipped', $contextSkip->steps[0]['status']);

        // Test empty config returns early with skipped step
        config(['workspace.cleanup_files' => []]);
        $contextEmpty = new WorkspaceContext(rootPath: $this->testDir);
        $processor->process($contextEmpty);
        $this->assertSame('skipped', $contextEmpty->steps[0]['status']);
        $this->assertSame('No cleanup files configured.', $contextEmpty->steps[0]['message']);
        @unlink($this->testDir.'/CLOUD.md');
    }

    public function test_custom_hook_workspace_processor(): void
    {
        $hookDir = $this->testDir.'/stubs/workspace/hooks';
        File::ensureDirectoryExists($hookDir);
        File::put($hookDir.'/post-install.php', '<?php File::put($context->rootPath."/hook_ran.txt", "yes");');

        $processor = app(CustomHookWorkspaceProcessor::class);
        $context = new WorkspaceContext(rootPath: $this->testDir);

        $processor->process($context);

        $this->assertFileExists($this->testDir.'/hook_ran.txt');
        $this->assertSame('executed', $context->steps[0]['status']);
    }
}
