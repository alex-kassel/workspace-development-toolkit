<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;

class WorkspaceRoutineMaterializationTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function composerScripts(): array
    {
        $composer = json_decode((string) File::get(base_path('composer.json')), true);
        $this->assertIsArray($composer);

        return (array) ($composer['scripts'] ?? []);
    }

    public function test_sync_publishes_composer_shortcuts_and_runner_subcommands(): void
    {
        Workspace::add('packages');

        $this->artisan('workspace:sync')->assertSuccessful();

        $scripts = $this->composerScripts();

        foreach (['workspace:check', 'workspace:push', 'workspace:pull'] as $script) {
            $this->assertArrayHasKey($script, $scripts);
            $this->assertContains('@php artisan '.$script.' --ansi', $scripts[$script]);
        }

        $runner = (string) File::get(base_path('workspace'));

        foreach (['check', 'push', 'pull'] as $command) {
            $this->assertStringContainsString("case '{$command}':", $runner);
        }

        $this->assertStringContainsString('workspace_run_artisan_command', $runner);
    }

    public function test_install_publishes_composer_shortcuts(): void
    {
        config(['workspace.initial_workspaces' => ['packages']]);

        @unlink(base_path('workspace.json'));
        @unlink(base_path('workspace'));

        $this->artisan('workspace:install')->assertSuccessful();

        $scripts = $this->composerScripts();

        $this->assertArrayHasKey('workspace:check', $scripts);
        $this->assertArrayHasKey('workspace:push', $scripts);
        $this->assertArrayHasKey('workspace:pull', $scripts);
    }
}
