<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;

class WorkspaceInstallCommandTest extends TestCase
{
    public function test_workspace_install_initializes_complete_environment(): void
    {
        // Place a dummy CLOUD.md to verify cleanup
        File::put(base_path('CLOUD.md'), '# Obsolete cloud instructions');

        // Clean any existing files in base_path for this test run
        @unlink(base_path('workspace.json'));
        @unlink(base_path('AGENTS.md'));
        @unlink(base_path('boost.json'));
        @unlink(base_path('workspace'));

        $this->artisan('workspace:install', ['workspace' => 'my_packages'])
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
