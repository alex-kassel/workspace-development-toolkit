<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Unit;

use AlexKassel\WorkspaceDevelopmentToolkit\Services\GitInspector;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class GitInspectorTest extends TestCase
{
    protected GitInspector $inspector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inspector = app(GitInspector::class);
    }

    public function test_has_git_repository_detects_git_directory(): void
    {
        $dir = $this->tempDir.'/git-pkg';
        File::ensureDirectoryExists($dir);
        $this->assertFalse($this->inspector->hasGitRepository($dir));

        File::ensureDirectoryExists($dir.'/.git');
        $this->assertTrue($this->inspector->hasGitRepository($dir));
    }

    public function test_is_clean_detects_clean_and_dirty_tree(): void
    {
        $dir = $this->tempDir.'/git-pkg';
        File::ensureDirectoryExists($dir.'/.git');

        Process::fake([
            '*' => Process::result(''),
        ]);

        $this->assertTrue($this->inspector->isClean($dir));

        Process::fake([
            '*' => Process::result("M file.txt\n?? new.txt"),
        ]);

        $this->assertFalse($this->inspector->isClean($dir));
    }

    public function test_has_unpushed_commits_with_upstream(): void
    {
        $dir = $this->tempDir.'/git-pkg';
        File::ensureDirectoryExists($dir.'/.git');

        Process::fake(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
            if (str_contains($cmd, 'rev-parse')) {
                return Process::result('origin/main');
            }
            if (str_contains($cmd, 'log')) {
                return Process::result('1a2f3c4 feat: local commit');
            }

            return Process::result('');
        });

        $this->assertTrue($this->inspector->hasUnpushedCommits($dir));
    }

    public function test_has_stashes_detects_stashes(): void
    {
        $dir = $this->tempDir.'/git-pkg';
        File::ensureDirectoryExists($dir.'/.git');

        Process::fake(['*' => Process::result("stash@{0}: WIP branch\n")]);

        $this->assertTrue($this->inspector->hasStashes($dir));

        Process::fake(['*' => Process::result('')]);

        $this->assertFalse($this->inspector->hasStashes($dir));
    }

    public function test_get_current_branch_and_tag(): void
    {
        $dir = $this->tempDir.'/git-pkg';
        File::ensureDirectoryExists($dir.'/.git');

        Process::fake(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
            if (str_contains($cmd, 'branch')) {
                return Process::result("main\n");
            }
            if (str_contains($cmd, 'tag')) {
                return Process::result("v1.2.0\nv1.1.0\n");
            }

            return Process::result('');
        });

        $this->assertSame('main', $this->inspector->getCurrentBranch($dir));
        $this->assertSame('v1.2.0', $this->inspector->getLatestTag($dir));
    }
}
