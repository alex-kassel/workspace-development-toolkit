<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class WorkspaceStatusCommandTest extends TestCase
{
    protected function scaffoldPackage(string $relPath, string $name): string
    {
        $dir = base_path($relPath);
        File::ensureDirectoryExists($dir.'/src');
        File::ensureDirectoryExists($dir.'/.git');
        File::put($dir.'/composer.json', json_encode(['name' => $name]));

        return $dir;
    }

    public function test_workspace_status_shows_empty_message_when_no_workspaces_exist(): void
    {
        $this->artisan('workspace:status')
            ->expectsOutputToContain('No workspaces registered.')
            ->expectsOutputToContain('php artisan workspace:add')
            ->assertSuccessful();
    }

    public function test_workspace_status_renders_dashboard_table(): void
    {
        $this->scaffoldPackage('packages/acme/my-pkg', 'acme/my-pkg');
        Workspace::add('packages', null, true);

        Process::fake([
            '*' => Process::result(output: ''),
        ]);

        $this->artisan('workspace:status')
            ->expectsOutputToContain('Workspace Status Dashboard')
            ->expectsOutputToContain('acme/my-pkg')
            ->expectsOutputToContain('Summary: 1 package(s) inspected')
            ->assertSuccessful();
    }

    public function test_workspace_status_filters_by_workspace(): void
    {
        $this->scaffoldPackage('packages/acme/pkg-a', 'acme/pkg-a');
        $this->scaffoldPackage('labs/service-a', 'internal/service-a');
        Workspace::add('packages', null, true);
        Workspace::add('labs', 'internal');

        Process::fake([
            '*' => Process::result(output: ''),
        ]);

        $this->artisan('workspace:status', ['--workspace' => 'packages'])
            ->expectsOutputToContain('acme/pkg-a')
            ->doesntExpectOutputToContain('internal/service-a')
            ->assertSuccessful();
    }

    public function test_workspace_status_fails_on_unknown_workspace(): void
    {
        Workspace::add('packages', null, true);

        $this->artisan('workspace:status', ['--workspace' => 'nonexistent'])
            ->expectsOutputToContain('Workspace [nonexistent] not found.')
            ->assertFailed();
    }

    public function test_workspace_status_outputs_json(): void
    {
        $this->scaffoldPackage('packages/acme/my-pkg', 'acme/my-pkg');
        Workspace::add('packages', null, true);

        Process::fake([
            '*' => Process::result(output: ''),
        ]);

        $output = $this->artisan('workspace:status', ['--json' => true])
            ->assertSuccessful()
            ->execute();

        // Check if output contains valid JSON
        $this->artisan('workspace:status', ['--json' => true])
            ->expectsOutputToContain('"package": "acme/my-pkg"')
            ->assertSuccessful();
    }

    public function test_workspace_status_dirty_flag_filters_clean_packages(): void
    {
        $this->scaffoldPackage('packages/acme/pkg-clean', 'acme/pkg-clean');
        $this->scaffoldPackage('packages/acme/pkg-dirty', 'acme/pkg-dirty');
        Workspace::add('packages', null, true);

        Process::fake(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
            $path = (string) ($process->path ?? '');
            if (str_contains($cmd, 'status') && str_contains($path, 'pkg-dirty')) {
                return Process::result(" M src/File.php\n");
            }

            return Process::result('');
        });

        $this->artisan('workspace:status', ['--dirty' => true])
            ->expectsOutputToContain('acme/pkg-dirty')
            ->doesntExpectOutputToContain('acme/pkg-clean')
            ->assertSuccessful();
    }

    public function test_workspace_status_dirty_flag_when_all_clean(): void
    {
        $this->scaffoldPackage('packages/acme/pkg-clean', 'acme/pkg-clean');
        Workspace::add('packages', null, true);

        Process::fake([
            '*' => Process::result(output: ''),
        ]);

        $this->artisan('workspace:status', ['--dirty' => true])
            ->expectsOutputToContain('All workspace packages are clean and synchronized!')
            ->assertSuccessful();
    }
}
