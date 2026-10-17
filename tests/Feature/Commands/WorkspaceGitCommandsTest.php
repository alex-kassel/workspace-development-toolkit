<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class WorkspaceGitCommandsTest extends TestCase
{
    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir().'/wdt_git_'.uniqid();
        File::ensureDirectoryExists($this->sandbox);
    }

    protected function tearDown(): void
    {
        if (isset($this->sandbox) && File::isDirectory($this->sandbox)) {
            File::deleteDirectory($this->sandbox);
        }

        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $files
     */
    private function initRepository(string $directory, ?string $remotePath, array $files = []): void
    {
        File::ensureDirectoryExists($directory);
        $this->assertGit($this->git($directory, ['init', '-q', '-b', 'main']));
        $this->assertGit($this->git($directory, ['config', 'user.email', 'test@example.com']));
        $this->assertGit($this->git($directory, ['config', 'user.name', 'Test User']));

        foreach ($files as $name => $content) {
            $path = $directory.DIRECTORY_SEPARATOR.$name;
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $content);
        }

        $this->assertGit($this->git($directory, ['add', '-A']));
        $this->assertGit($this->git($directory, ['commit', '-qm', 'init']));

        if ($remotePath !== null) {
            File::ensureDirectoryExists(dirname($remotePath));
            $this->assertGit($this->git($this->sandbox, ['init', '--bare', '-q', '-b', 'main', $remotePath]));
            $this->assertGit($this->git($directory, ['remote', 'add', 'origin', $remotePath]));
            $this->assertGit($this->git($directory, ['push', '-u', 'origin', 'main']));
        }
    }

    /**
     * @param  array<int, string>  $packages
     * @param  array<int, string>  $packagesWithoutRemote
     * @return array{host: string, packages: array<string, string>}
     */
    private function bootstrapWorkspace(array $packages = ['acme/pkg-a'], array $packagesWithoutRemote = []): array
    {
        Workspace::add('packages');

        $remotes = [];

        foreach ($packages as $package) {
            $remote = $this->sandbox.'/remotes/'.$package.'.git';
            $remotes[$package] = $remote;

            $this->initRepository(
                base_path('packages/'.$package),
                in_array($package, $packagesWithoutRemote, true) ? null : $remote,
                [
                    'composer.json' => (string) json_encode(['name' => $package, 'version' => '0.0.1'], JSON_PRETTY_PRINT),
                    '.gitignore' => "/vendor/\n/workspace.json\n",
                    'README.md' => "# {$package}\n",
                ],
            );
        }

        Workspace::setRepositoryTemplate($this->sandbox.'/remotes/{package}.git');
        Workspace::sync();

        $hostRemote = $this->sandbox.'/remotes/host.git';
        $this->initRepository(base_path(), $hostRemote, ['README.md' => "# Host\n"]);

        return ['host' => $hostRemote, 'packages' => $remotes];
    }

    private function git(string $directory, array $arguments): ProcessResult
    {
        return Process::timeout(60)->path($directory)->run(array_merge(['git'], $arguments));
    }

    private function assertGit(ProcessResult $result): void
    {
        $this->assertTrue(
            $result->successful(),
            'Git command failed: '.$result->errorOutput().$result->output()
        );
    }

    private function gitDirectory(string $repository): string
    {
        return '--git-dir='.$repository;
    }

    private function cloneRepository(string $remote, string $target): string
    {
        File::ensureDirectoryExists(dirname($target));
        $this->assertGit($this->git($this->sandbox, ['clone', '-q', $remote, $target]));
        $this->assertGit($this->git($target, ['config', 'user.email', 'test@example.com']));
        $this->assertGit($this->git($target, ['config', 'user.name', 'Test User']));

        return $target;
    }

    public function test_check_passes_for_clean_and_synced_repositories(): void
    {
        $this->bootstrapWorkspace();

        $this->artisan('workspace:check')
            ->expectsOutputToContain('Workspace Git Preflight')
            ->expectsOutputToContain('clean')
            ->assertSuccessful();
    }

    public function test_check_fails_for_dirty_package(): void
    {
        $this->bootstrapWorkspace();
        File::put(base_path('packages/acme/pkg-a/dirty.txt'), 'uncommitted');

        $this->artisan('workspace:check')
            ->expectsOutputToContain('Working tree is dirty')
            ->assertFailed();
    }

    public function test_check_fails_for_unpublished_package(): void
    {
        $this->bootstrapWorkspace(['acme/pkg-a', 'acme/pkg-b'], ['acme/pkg-b']);

        $this->artisan('workspace:check')
            ->expectsOutputToContain('unpushed')
            ->assertFailed();
    }

    public function test_push_pushes_commits_and_tags(): void
    {
        $bootstrap = $this->bootstrapWorkspace();
        $packagePath = base_path('packages/acme/pkg-a');

        File::put($packagePath.'/feature.txt', 'feature');
        $this->assertGit($this->git($packagePath, ['add', '-A']));
        $this->assertGit($this->git($packagePath, ['commit', '-qm', 'feat: add feature']));
        $this->assertGit($this->git($packagePath, ['tag', '-a', 'v0.0.2', '-m', 'v0.0.2']));

        $localHead = trim($this->git($packagePath, ['rev-parse', 'HEAD'])->output());
        $remote = $bootstrap['packages']['acme/pkg-a'];

        $this->artisan('workspace:push', ['--dry-run' => true])
            ->expectsOutputToContain('Would push')
            ->assertSuccessful();

        $this->artisan('workspace:push')
            ->expectsOutputToContain('Pushed')
            ->assertSuccessful();

        $remoteHead = trim($this->git($this->sandbox, [$this->gitDirectory($remote), 'rev-parse', 'main'])->output());
        $this->assertSame($localHead, $remoteHead);

        $remoteTags = $this->git($this->sandbox, [$this->gitDirectory($remote), 'tag', '-l'])->output();
        $this->assertStringContainsString('v0.0.2', $remoteTags);
    }

    public function test_push_fails_for_dirty_package(): void
    {
        $this->bootstrapWorkspace();
        File::put(base_path('packages/acme/pkg-a/dirty.txt'), 'uncommitted');

        $this->artisan('workspace:push')
            ->expectsOutputToContain('Working tree is dirty')
            ->assertFailed();
    }

    public function test_pull_fast_forwards_host_and_package(): void
    {
        $bootstrap = $this->bootstrapWorkspace();

        $packageClone = $this->cloneRepository($bootstrap['packages']['acme/pkg-a'], $this->sandbox.'/clones/pkg-a');
        File::put($packageClone.'/remote-change.txt', 'remote change');
        $this->assertGit($this->git($packageClone, ['add', '-A']));
        $this->assertGit($this->git($packageClone, ['commit', '-qm', 'feat: remote change']));
        $this->assertGit($this->git($packageClone, ['push', 'origin', 'main']));

        $hostClone = $this->cloneRepository($bootstrap['host'], $this->sandbox.'/clones/host');
        File::put($hostClone.'/README.md', "# Host updated on another machine\n");
        $this->assertGit($this->git($hostClone, ['add', '-A']));
        $this->assertGit($this->git($hostClone, ['commit', '-qm', 'docs: update host readme']));
        $this->assertGit($this->git($hostClone, ['push', 'origin', 'main']));

        $this->artisan('workspace:pull', [
            '--no-install' => true,
            '--no-migrate' => true,
            '--no-assets' => true,
        ])
            ->expectsOutputToContain('all repositories are synchronized')
            ->assertSuccessful();

        $this->assertFileExists(base_path('packages/acme/pkg-a/remote-change.txt'));
        $this->assertStringContainsString('updated on another machine', (string) File::get(base_path('README.md')));
    }

    public function test_pull_restores_missing_package(): void
    {
        $this->bootstrapWorkspace();

        File::deleteDirectory(base_path('packages/acme/pkg-a'));
        $this->assertDirectoryDoesNotExist(base_path('packages/acme/pkg-a'));

        $this->artisan('workspace:pull', [
            '--no-install' => true,
            '--no-migrate' => true,
            '--no-assets' => true,
        ])
            ->expectsOutputToContain('all repositories are synchronized')
            ->assertSuccessful();

        $this->assertFileExists(base_path('packages/acme/pkg-a/composer.json'));
    }

    public function test_pull_does_not_overwrite_dirty_package(): void
    {
        $this->bootstrapWorkspace();

        $packagePath = base_path('packages/acme/pkg-a');
        File::put($packagePath.'/dirty.txt', 'local work');

        $this->artisan('workspace:pull', [
            '--no-install' => true,
            '--no-migrate' => true,
            '--no-assets' => true,
        ])->assertFailed();

        $this->assertSame('local work', (string) File::get($packagePath.'/dirty.txt'));
    }
}
