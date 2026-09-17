<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Unit\Actions;

use AlexKassel\WorkspaceDevelopmentToolkit\Actions\BaseAction;
use AlexKassel\WorkspaceDevelopmentToolkit\Actions\CleanupHostArtifactsAction;
use AlexKassel\WorkspaceDevelopmentToolkit\Actions\PublishWorkspaceRunnerAction;
use AlexKassel\WorkspaceDevelopmentToolkit\Actions\RunCustomHookAction;
use AlexKassel\WorkspaceDevelopmentToolkit\Actions\SetupAgentsGuidelineAction;
use AlexKassel\WorkspaceDevelopmentToolkit\Actions\SetupBoostConfigAction;
use AlexKassel\WorkspaceDevelopmentToolkit\Actions\SetupWorkspaceManifestAction;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\ActionExecutionException;
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

    public function test_setup_workspace_manifest_action_with_multiple_workspaces(): void
    {
        $action = new SetupWorkspaceManifestAction;
        $steps = iterator_to_array($action->execute(
            rootPath: $this->testDir,
            workspaces: ['packages', 'modules'],
        ));

        $manifestFile = $this->testDir.'/workspace.json';
        $this->assertFileExists($manifestFile);
        $this->assertDirectoryExists($this->testDir.'/packages');
        $this->assertDirectoryExists($this->testDir.'/modules');

        $data = json_decode((string) File::get($manifestFile), true);
        $this->assertSame('packages', $data['default']);
        $this->assertArrayHasKey('packages', $data['workspaces']);
        $this->assertArrayHasKey('modules', $data['workspaces']);

        $this->assertNotEmpty($steps);
        $this->assertSame('created', $steps[0]->status);
    }

    public function test_setup_workspace_manifest_action_with_zero_workspaces(): void
    {
        $action = new SetupWorkspaceManifestAction;
        $steps = iterator_to_array($action->execute(
            rootPath: $this->testDir,
            workspaces: [],
        ));

        $manifestFile = $this->testDir.'/workspace.json';
        $this->assertFileExists($manifestFile);

        $data = json_decode((string) File::get($manifestFile), true);
        $this->assertNull($data['default']);
        $this->assertSame([], $data['workspaces']);

        $this->assertSame('created', $steps[0]->status);
        $this->assertStringContainsString('zero workspaces', $steps[0]->message);
    }

    public function test_setup_workspace_manifest_action_run_eagerly(): void
    {
        $action = new SetupWorkspaceManifestAction;
        $action->run($this->testDir, ['packages']);

        $this->assertFileExists($this->testDir.'/workspace.json');
    }

    public function test_setup_agents_guideline_action_standalone(): void
    {
        $action = new SetupAgentsGuidelineAction;
        $steps = iterator_to_array($action->execute(rootPath: $this->testDir));

        $agentsFile = $this->testDir.'/AGENTS.md';
        $this->assertFileExists($agentsFile);
        $this->assertFalse(is_link($agentsFile));
        $this->assertSame('created', $steps[0]->status);
    }

    public function test_setup_agents_guideline_action_self_symlink(): void
    {
        $pkgPath = 'packages/alex-kassel/test-toolkit';
        $pkgFull = $this->testDir.'/'.$pkgPath;
        File::ensureDirectoryExists($pkgFull.'/stubs');
        File::put($pkgFull.'/stubs/AGENTS.md.stub', '# Test Toolkit Rules');

        $action = new SetupAgentsGuidelineAction;
        $steps = iterator_to_array($action->execute(
            rootPath: $this->testDir,
            isSelf: true,
            selfPackagePath: $pkgPath,
        ));

        $agentsFile = $this->testDir.'/AGENTS.md';
        $this->assertTrue(is_link($agentsFile));
        $this->assertSame('linked', $steps[0]->status);
    }

    public function test_setup_boost_config_action_creates_and_updates(): void
    {
        $action = new SetupBoostConfigAction;
        $steps = iterator_to_array($action->execute(
            rootPath: $this->testDir,
            packages: ['alex-kassel/workspace-development-toolkit'],
        ));

        $boostFile = $this->testDir.'/boost.json';
        $this->assertFileExists($boostFile);
        $this->assertSame('created', $steps[0]->status);

        $content = json_decode((string) File::get($boostFile), true);
        $this->assertFalse($content['guidelines']);
        $this->assertContains('alex-kassel/workspace-development-toolkit', $content['packages']);

        // Update existing with different package
        $stepsUpdate = iterator_to_array($action->execute(
            rootPath: $this->testDir,
            packages: ['alex-kassel/workspace-development-toolkit', 'acme/custom-pkg'],
        ));
        $this->assertSame('updated', $stepsUpdate[0]->status);

        $updated = json_decode((string) File::get($boostFile), true);
        $this->assertContains('acme/custom-pkg', $updated['packages']);
    }

    public function test_publish_workspace_runner_action(): void
    {
        $action = new PublishWorkspaceRunnerAction;
        $steps = iterator_to_array($action->execute(rootPath: $this->testDir));

        $runner = $this->testDir.'/workspace';
        $this->assertFileExists($runner);
        $this->assertSame('created', $steps[0]->status);

        $content = File::get($runner);
        $this->assertStringContainsString('workspace.json', $content);
        $this->assertStringNotContainsString('{{ manifestPath }}', $content);
        $this->assertStringNotContainsString('{{ runnerName }}', $content);

        // Second call without force should be skipped
        $stepsSecond = iterator_to_array($action->execute(rootPath: $this->testDir));
        $this->assertSame('skipped', $stepsSecond[0]->status);
    }

    public function test_cleanup_host_artifacts_action(): void
    {
        File::put($this->testDir.'/CLOUD.md', '# Cloud rules');
        File::ensureDirectoryExists($this->testDir.'/.cloud');
        File::put($this->testDir.'/.cloud/config.json', '{}');

        $action = new CleanupHostArtifactsAction;
        $steps = iterator_to_array($action->execute(
            rootPath: $this->testDir,
            artifacts: ['CLOUD.md', '.cloud'],
        ));

        $this->assertFileDoesNotExist($this->testDir.'/CLOUD.md');
        $this->assertDirectoryDoesNotExist($this->testDir.'/.cloud');
        $this->assertCount(2, $steps);
        $this->assertSame('cleaned', $steps[0]->status);
        $this->assertSame('Removed [CLOUD.md].', $steps[0]->message);
        $this->assertSame('cleaned', $steps[1]->status);
        $this->assertSame('Removed [.cloud].', $steps[1]->message);

        // Test with non-existent artifacts
        $stepsSkip = iterator_to_array($action->execute(
            rootPath: $this->testDir,
            artifacts: ['CLOUD.md'],
        ));
        $this->assertSame('skipped', $stepsSkip[0]->status);
    }

    public function test_run_custom_hook_action(): void
    {
        $hookDir = $this->testDir.'/stubs/workspace/hooks';
        File::ensureDirectoryExists($hookDir);
        File::put($hookDir.'/post-install.php', '<?php File::put($rootPath."/hook_ran.txt", "yes");');

        $action = new RunCustomHookAction;
        $steps = iterator_to_array($action->execute(
            rootPath: $this->testDir,
            candidatePaths: [$hookDir.'/post-install.php'],
            contextVariables: ['rootPath' => $this->testDir],
        ));

        $this->assertFileExists($this->testDir.'/hook_ran.txt');
        $this->assertSame('executed', $steps[0]->status);
    }

    public function test_base_action_throws_exception_when_execute_method_is_missing(): void
    {
        $anonymousAction = new class extends BaseAction {};

        $this->expectException(ActionExecutionException::class);
        $this->expectExceptionMessage('must implement an execute() generator method');

        $anonymousAction->run();
    }
}
