<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Unit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ManifestRepository;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceStatusCollector;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class WorkspaceStatusCollectorTest extends TestCase
{
    protected function scaffoldPackage(string $relPath, string $name): string
    {
        $dir = base_path($relPath);
        File::ensureDirectoryExists($dir.'/src');
        File::ensureDirectoryExists($dir.'/.git');
        File::put($dir.'/composer.json', json_encode(['name' => $name]));

        return $dir;
    }

    public function test_it_collects_package_statuses_across_workspaces(): void
    {
        $this->scaffoldPackage('packages/acme/pkg-a', 'acme/pkg-a');
        $this->scaffoldPackage('packages/acme/pkg-b', 'acme/pkg-b');
        Workspace::add('packages', null, true);

        Process::fake([
            '*' => Process::result(output: ''),
        ]);

        /** @var WorkspaceStatusCollector $collector */
        $collector = $this->app->make(WorkspaceStatusCollector::class);
        $statuses = $collector->collect();

        $this->assertCount(2, $statuses);
        $this->assertSame('acme/pkg-a', $statuses[0]->packageName);
        $this->assertSame('packages', $statuses[0]->workspace);
        $this->assertSame('packages/acme/pkg-a', $statuses[0]->relativePath);
        $this->assertSame('Clean', $statuses[0]->gitStatus);
    }

    public function test_it_filters_by_workspace_name(): void
    {
        $this->scaffoldPackage('packages/acme/pkg-a', 'acme/pkg-a');
        $this->scaffoldPackage('labs/service-a', 'internal/service-a');
        Workspace::add('packages', null, true);
        Workspace::add('labs', 'internal');

        Process::fake([
            '*' => Process::result(output: ''),
        ]);

        /** @var WorkspaceStatusCollector $collector */
        $collector = $this->app->make(WorkspaceStatusCollector::class);

        $packagesOnly = $collector->collect('packages');
        $this->assertCount(1, $packagesOnly);
        $this->assertSame('acme/pkg-a', $packagesOnly[0]->packageName);

        $labsOnly = $collector->collect('labs');
        $this->assertCount(1, $labsOnly);
        $this->assertSame('internal/service-a', $labsOnly[0]->packageName);

        $nonExistent = $collector->collect('unknown');
        $this->assertEmpty($nonExistent);
    }

    public function test_it_filters_by_only_dirty_flag(): void
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

        /** @var WorkspaceStatusCollector $collector */
        $collector = $this->app->make(WorkspaceStatusCollector::class);

        $dirtyOnly = $collector->collect(onlyDirty: true);
        $this->assertCount(1, $dirtyOnly);
        $this->assertSame('acme/pkg-dirty', $dirtyOnly[0]->packageName);
        $this->assertStringContainsString('1 modified', $dirtyOnly[0]->gitStatus);
        $this->assertTrue($dirtyOnly[0]->hasIssues());
    }

    public function test_it_handles_missing_package_directory(): void
    {
        Workspace::add('packages', null, true);

        // Manually record package in manifest without creating physical directory
        $manifest = $this->app->make(ManifestRepository::class)->load();
        $manifest['workspaces']['packages']['packages'] = [['name' => 'acme/ghost-pkg']];
        $this->app->make(ManifestRepository::class)->save($manifest);

        /** @var WorkspaceStatusCollector $collector */
        $collector = $this->app->make(WorkspaceStatusCollector::class);
        $statuses = $collector->collect();

        $this->assertCount(1, $statuses);
        $this->assertSame('acme/ghost-pkg', $statuses[0]->packageName);
        $this->assertSame('Directory missing', $statuses[0]->gitStatus);
        $this->assertSame('Corrupted', $statuses[0]->installStatus);
        $this->assertTrue($statuses[0]->hasIssues());
    }

    public function test_package_status_to_array_format(): void
    {
        $this->scaffoldPackage('packages/acme/pkg-a', 'acme/pkg-a');
        Workspace::add('packages', null, true);

        Process::fake([
            '*' => Process::result(output: ''),
        ]);

        /** @var WorkspaceStatusCollector $collector */
        $collector = $this->app->make(WorkspaceStatusCollector::class);
        $statuses = $collector->collect();

        $array = $statuses[0]->toArray();
        $this->assertArrayHasKey('package', $array);
        $this->assertArrayHasKey('workspace', $array);
        $this->assertArrayHasKey('path', $array);
        $this->assertArrayHasKey('branch', $array);
        $this->assertArrayHasKey('git_status', $array);
        $this->assertArrayHasKey('upstream', $array);
        $this->assertArrayHasKey('installed', $array);
        $this->assertArrayHasKey('audit', $array);
        $this->assertArrayHasKey('has_issues', $array);
    }
}
