<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Unit\Actions;

use AlexKassel\WorkspaceDevelopmentToolkit\Actions\CleanupHostArtifactsAction;
use AlexKassel\WorkspaceDevelopmentToolkit\Actions\PublishWorkspaceRunnerAction;
use AlexKassel\WorkspaceDevelopmentToolkit\Actions\RunCustomHookAction;
use AlexKassel\WorkspaceDevelopmentToolkit\Actions\SetupAgentsGuidelineAction;
use AlexKassel\WorkspaceDevelopmentToolkit\Actions\SetupBoostConfigAction;
use AlexKassel\WorkspaceDevelopmentToolkit\Actions\SetupWorkspaceManifestAction;
use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\InstallContext;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;

class ActionsTest extends TestCase
{
    protected string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = base_path('test_actions_env');
        File::ensureDirectoryExists($this->testDir);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->testDir)) {
            File::deleteDirectory($this->testDir);
        }
        parent::tearDown();
    }

    public function test_setup_workspace_manifest_action_creates_and_skips(): void
    {
        $action = new SetupWorkspaceManifestAction();
        $context = new InstallContext(rootPath: $this->testDir, defaultWorkspace: 'custom_pkgs');

        $action->execute($context);

        $manifestFile = $this->testDir.'/workspace.json';
        $this->assertFileExists($manifestFile);
        $this->assertDirectoryExists($this->testDir.'/custom_pkgs');

        $data = json_decode((string) File::get($manifestFile), true);
        $this->assertArrayHasKey('custom_pkgs', $data['workspaces']);
        $this->assertTrue($data['workspaces']['custom_pkgs']['is_default']);

        // Running again without force should skip
        $context2 = new InstallContext(rootPath: $this->testDir, defaultWorkspace: 'custom_pkgs');
        $action->execute($context2);
        $this->assertSame('skipped', $context2->steps[0]['status']);
    }

    public function test_setup_agents_guideline_action_standalone(): void
    {
        $action = new SetupAgentsGuidelineAction();
        $context = new InstallContext(rootPath: $this->testDir);

        $action->execute($context);

        $agentsFile = $this->testDir.'/AGENTS.md';
        $this->assertFileExists($agentsFile);
        $this->assertFalse(is_link($agentsFile));
        $this->assertStringContainsString('Project Rules & Guidelines', (string) File::get($agentsFile));
    }

    public function test_setup_agents_guideline_action_self_symlink(): void
    {
        $pkgPath = 'packages/alex-kassel/test-toolkit';
        $pkgFull = $this->testDir.'/'.$pkgPath;
        File::ensureDirectoryExists($pkgFull.'/stubs');
        File::put($pkgFull.'/stubs/AGENTS.md.stub', '# Test Toolkit Rules');

        $action = new SetupAgentsGuidelineAction();
        $context = new InstallContext(
            rootPath: $this->testDir,
            isSelf: true,
            selfPackagePath: $pkgPath,
        );

        $action->execute($context);

        $agentsFile = $this->testDir.'/AGENTS.md';
        $this->assertTrue(is_link($agentsFile));
        $this->assertSame('# Test Toolkit Rules', (string) File::get($agentsFile));
    }

    public function test_setup_boost_config_action_creates_and_updates(): void
    {
        $action = new SetupBoostConfigAction();
        $context = new InstallContext(rootPath: $this->testDir);

        $action->execute($context);

        $boostFile = $this->testDir.'/boost.json';
        $this->assertFileExists($boostFile);

        $content = json_decode((string) File::get($boostFile), true);
        $this->assertFalse($content['guidelines']);
        $this->assertContains('alex-kassel/workspace-development-toolkit', $content['packages']);

        // Test updating existing with guidelines: true
        File::put($boostFile, json_encode(['guidelines' => true, 'packages' => ['other/pkg']]));
        $context2 = new InstallContext(rootPath: $this->testDir);
        $action->execute($context2);

        $updated = json_decode((string) File::get($boostFile), true);
        $this->assertFalse($updated['guidelines']);
        $this->assertContains('alex-kassel/workspace-development-toolkit', $updated['packages']);
        $this->assertContains('other/pkg', $updated['packages']);
    }

    public function test_publish_workspace_runner_action(): void
    {
        $action = new PublishWorkspaceRunnerAction();
        $context = new InstallContext(rootPath: $this->testDir);

        $action->execute($context);

        $runner = $this->testDir.'/workspace';
        $this->assertFileExists($runner);
        $this->assertStringContainsString('#!/usr/bin/env php', (string) File::get($runner));
    }

    public function test_cleanup_host_artifacts_action_removes_cloud_md(): void
    {
        File::put($this->testDir.'/CLOUD.md', '# Cloud rules');

        $action = new CleanupHostArtifactsAction();
        $context = new InstallContext(rootPath: $this->testDir);

        $action->execute($context);

        $this->assertFileDoesNotExist($this->testDir.'/CLOUD.md');
        $this->assertSame('cleaned', $context->steps[0]['status']);

        // Test skip cleanup
        File::put($this->testDir.'/CLOUD.md', '# Cloud rules again');
        $contextSkip = new InstallContext(rootPath: $this->testDir, skipCleanup: true);
        $action->execute($contextSkip);

        $this->assertFileExists($this->testDir.'/CLOUD.md');
        $this->assertSame('skipped', $contextSkip->steps[0]['status']);
    }

    public function test_run_custom_hook_action(): void
    {
        $hookDir = $this->testDir.'/stubs/workspace/hooks';
        File::ensureDirectoryExists($hookDir);
        File::put($hookDir.'/post-install.php', '<?php File::put($context->rootPath."/hook_ran.txt", "yes");');

        $action = new RunCustomHookAction();
        $context = new InstallContext(rootPath: $this->testDir);

        $action->execute($context);

        $this->assertFileExists($this->testDir.'/hook_ran.txt');
        $this->assertSame('executed', $context->steps[0]['status']);
    }
}
