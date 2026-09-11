<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Unit;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\CheckResult;
use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\PackageVerifier;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ReleaseChecker;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class ReleaseCheckerTest extends TestCase
{
    protected ReleaseChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->checker = app(ReleaseChecker::class);
    }

    public function test_release_check_returns_blocked_when_git_is_missing(): void
    {
        Workspace::add('packages', null, true);
        $dir = $this->tempDir.'/packages/acme/no-git-pkg';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/composer.json', json_encode(['name' => 'acme/no-git-pkg']));
        File::put($dir.'/README.md', "# No Git\n\n## Requirements\n\n## Installation\n\n## Usage\n\n## Testing\n\n## License\n");

        Process::fake([
            '*' => Process::result('OK'),
        ]);

        $result = $this->checker->check('acme/no-git-pkg', fast: true);

        $this->assertSame('BLOCKED', $result['verdict']);
        $this->assertSame('failed', $result['checks']['git_repo']['status']);
    }

    public function test_release_check_returns_ready_when_all_pass(): void
    {
        Workspace::add('packages', null, true);
        $dir = $this->tempDir.'/packages/acme/perfect-pkg';
        File::ensureDirectoryExists($dir.'/.git');
        File::put($dir.'/composer.json', json_encode(['name' => 'acme/perfect-pkg']));
        File::put($dir.'/.gitattributes', "/tests export-ignore\n");
        File::put($dir.'/README.md', "# Perfect Pkg\n\n## Requirements\n\n## Installation\n\n## Usage\n\n## Testing\n\n## License\n");

        // Mock PackageVerifier so it does not fail
        $this->app->bind(PackageVerifier::class, function () {
            $mock = $this->createMock(PackageVerifier::class);
            $mock->method('checkAll')->willReturn([
                new CheckResult('composer', 'acme/perfect-pkg', 'passed', 'OK'),
            ]);

            return $mock;
        });

        // Re-resolve checker with mock
        $this->checker = $this->app->make(ReleaseChecker::class);

        Process::fake(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
            if (str_contains($cmd, 'status')) {
                return Process::result('');
            }
            if (str_contains($cmd, 'tag')) {
                return Process::result("v1.0.0\n");
            }

            return Process::result('OK');
        });

        $result = $this->checker->check('acme/perfect-pkg', fast: true);

        $this->assertSame('READY', $result['verdict']);
        $this->assertSame('v1.0.0', $result['latest_tag']);
    }
}
