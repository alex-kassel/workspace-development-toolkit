<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;

class WorkspaceInstallCommandTest extends TestCase
{
    public function test_workspace_install_initializes_complete_environment(): void
    {
        config(['workspace.initial_workspaces' => ['my_packages']]);

        // Place a dummy CLOUD.md to verify cleanup
        File::put(base_path('CLOUD.md'), '# Obsolete cloud instructions');

        // Clean any existing files in base_path for this test run
        @unlink(base_path('workspace.json'));
        @unlink(base_path('AGENTS.md'));
        @unlink(base_path('boost.json'));
        @unlink(base_path('workspace'));

        $this->artisan('workspace:install')
            ->expectsOutputToContain('Initializing Workspace Development Toolkit...')
            ->expectsOutputToContain('Workspace environment initialized successfully!')
            ->assertSuccessful();

        $this->assertFileExists(base_path('workspace.json'));
        $this->assertDirectoryExists(base_path('my_packages'));
        $this->assertFileExists(base_path('AGENTS.md'));
        $this->assertFileExists(base_path('boost.json'));
        $this->assertFileExists(base_path('workspace'));
        $this->assertFileDoesNotExist(base_path('CLOUD.md'));

        $boostData = json_decode((string) File::get(base_path('boost.json')), true);
        $this->assertFalse($boostData['guidelines']);
        $this->assertContains('alex-kassel/workspace-development-toolkit', $boostData['packages']);

        if (File::isDirectory(base_path('my_packages'))) {
            File::deleteDirectory(base_path('my_packages'));
        }
    }

    public function test_workspace_install_with_zero_initial_workspaces(): void
    {
        config(['workspace.initial_workspaces' => []]);

        @unlink(base_path('workspace.json'));

        $this->artisan('workspace:install')
            ->expectsOutputToContain('zero workspaces')
            ->assertSuccessful();

        $data = json_decode((string) File::get(base_path('workspace.json')), true);
        $this->assertNull($data['default']);
        $this->assertSame([], $data['workspaces']);
    }

    public function test_workspace_install_with_default_option(): void
    {
        config(['workspace.initial_workspaces' => ['pkg_a', 'pkg_b']]);

        @unlink(base_path('workspace.json'));

        $this->artisan('workspace:install', ['--default' => 'pkg_b'])
            ->assertSuccessful();

        $data = json_decode((string) File::get(base_path('workspace.json')), true);
        $this->assertSame('pkg_b', $data['default']);
        $this->assertArrayHasKey('pkg_a', $data['workspaces']);
        $this->assertArrayHasKey('pkg_b', $data['workspaces']);

        File::deleteDirectory(base_path('pkg_a'));
        File::deleteDirectory(base_path('pkg_b'));
    }

    public function test_workspace_install_with_self_option(): void
    {
        $pkgPath = 'packages/alex-kassel/self-toolkit';
        $stubDir = base_path($pkgPath.'/stubs');
        File::ensureDirectoryExists($stubDir);
        File::put($stubDir.'/AGENTS.md.stub', '# Self Toolkit Rules');

        @unlink(base_path('AGENTS.md'));

        try {
            $this->artisan('workspace:install', [
                '--self' => true,
                '--package-path' => $pkgPath,
            ])->assertSuccessful();

            $agentsFile = base_path('AGENTS.md');
            $this->assertTrue(is_link($agentsFile));
        } finally {
            if (File::isDirectory(base_path('packages/alex-kassel/self-toolkit'))) {
                File::deleteDirectory(base_path('packages/alex-kassel/self-toolkit'));
            }
        }
    }

    public function test_workspace_install_respects_skip_cleanup_flag(): void
    {
        File::put(base_path('CLOUD.md'), '# Kept cloud file');

        $this->artisan('workspace:install', [
            '--skip-cleanup' => true,
        ])->assertSuccessful();

        $this->assertFileExists(base_path('CLOUD.md'));
        @unlink(base_path('CLOUD.md'));
    }

    public function test_workspace_install_executes_custom_post_install_hook(): void
    {
        $hookDir = base_path('stubs/workspace/hooks');
        File::ensureDirectoryExists($hookDir);
        File::put($hookDir.'/post-install.php', '<?php File::put(base_path("custom_hook_output.txt"), "hook executed!");');

        try {
            $this->artisan('workspace:install')
                ->expectsOutputToContain('[hook] Executed custom post-install hook')
                ->assertSuccessful();

            $this->assertFileExists(base_path('custom_hook_output.txt'));
            $this->assertSame('hook executed!', (string) File::get(base_path('custom_hook_output.txt')));
        } finally {
            @unlink(base_path('custom_hook_output.txt'));
            File::deleteDirectory(base_path('stubs/workspace/hooks'));
        }
    }
}
