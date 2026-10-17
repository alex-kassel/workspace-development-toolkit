<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ManifestRepository;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

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

        $expected = [
            'workspace:check' => '@php workspace check',
            'workspace:push' => '@php workspace push',
            'workspace:pull' => '@php workspace pull',
        ];

        foreach ($expected as $script => $command) {
            $this->assertArrayHasKey($script, $scripts);
            $this->assertContains($command, $scripts[$script]);
        }

        $runner = (string) File::get(base_path('workspace'));

        foreach (['check', 'push', 'pull'] as $command) {
            $this->assertStringContainsString("case '{$command}':", $runner);
        }

        $this->assertStringContainsString('workspace_run_artisan_command', $runner);
        $this->assertStringContainsString('workspace_missing_packages', $runner);
        $this->assertStringContainsString('workspace_restore($rootPath, $manifestPath, $args)', $runner);
    }

    public function test_runner_reports_missing_packages_before_booting_laravel(): void
    {
        Workspace::add('packages');
        $this->artisan('workspace:sync')->assertSuccessful();

        $manifest = app(ManifestRepository::class)->load();
        $manifest['workspaces']['packages']['packages'] = [['name' => 'acme/ghost-package']];
        app(ManifestRepository::class)->save($manifest);

        $result = Process::path(base_path())->run([PHP_BINARY, base_path('workspace'), 'check']);

        $this->assertSame(1, $result->exitCode());
        $this->assertStringContainsString('Missing workspace packages', $result->errorOutput().$result->output());
        $this->assertStringContainsString('php workspace pull', $result->errorOutput());
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
