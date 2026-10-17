<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Unit;

use AlexKassel\WorkspaceDevelopmentToolkit\Services\GitInspector;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class GitInspectorTrackingTest extends TestCase
{
    protected GitInspector $inspector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->inspector = app(GitInspector::class);
    }

    public function test_get_tracking_details_reports_divergence(): void
    {
        $dir = $this->fakeRepository();

        Process::fake(function ($process) {
            $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

            return match (true) {
                str_contains($command, 'rev-parse --abbrev-ref') => Process::result('origin/main'),
                str_contains($command, 'rev-list --left-right') => Process::result("2\t3"),
                default => Process::result(''),
            };
        });

        $details = $this->inspector->getTrackingDetails($dir);

        $this->assertSame('origin/main', $details['upstream']);
        $this->assertSame(2, $details['ahead']);
        $this->assertSame(3, $details['behind']);
        $this->assertSame('Diverged', $details['status']);
    }

    public function test_get_tracking_details_reports_unpublished_branch(): void
    {
        $dir = $this->fakeRepository();

        Process::fake(function ($process) {
            $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

            return match (true) {
                str_contains($command, 'rev-parse --abbrev-ref') => Process::result('', '', 1),
                str_contains($command, 'rev-list --count HEAD') => Process::result("4\n"),
                default => Process::result(''),
            };
        });

        $details = $this->inspector->getTrackingDetails($dir);

        $this->assertNull($details['upstream']);
        $this->assertSame(4, $details['ahead']);
        $this->assertSame('Unpublished', $details['status']);
    }

    public function test_get_dirty_files_returns_porcelain_lines(): void
    {
        $dir = $this->fakeRepository();

        Process::fake(['*' => Process::result(" M src/File.php\n?? new.txt\n")]);

        $this->assertSame(['M src/File.php', '?? new.txt'], $this->inspector->getDirtyFiles($dir));
    }

    public function test_get_unpushed_tags_returns_local_only_tags(): void
    {
        $dir = $this->fakeRepository();

        Process::fake(['*' => Process::result("v1.0.0\nv1.1.0\n")]);

        $this->assertSame(['v1.0.0', 'v1.1.0'], $this->inspector->getUnpushedTags($dir));
    }

    public function test_get_stashes_count_counts_entries(): void
    {
        $dir = $this->fakeRepository();

        Process::fake(['*' => Process::result("stash@{0}: WIP\nstash@{1}: WIP\n")]);

        $this->assertSame(2, $this->inspector->getStashesCount($dir));
    }

    public function test_get_remote_url_returns_origin_url(): void
    {
        $dir = $this->fakeRepository();

        Process::fake(['*' => Process::result("git@github.com:acme/pkg.git\n")]);

        $this->assertSame('git@github.com:acme/pkg.git', $this->inspector->getRemoteUrl($dir));
    }

    private function fakeRepository(): string
    {
        $dir = $this->tempDir.'/git-pkg';
        File::ensureDirectoryExists($dir.'/.git');

        return $dir;
    }
}
