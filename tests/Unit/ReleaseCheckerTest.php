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
        File::put($dir.'/RELEASE-GATE.md', "Status: PASSED\naudit commit 1234567\n");
        File::put($dir.'/README.md', "# Perfect Pkg\n\n## Requirements\n\n## Installation\n\n## Usage\n\n## Testing\n\n## License\n");

        // Stub PackageVerifier so it does not fail
        $stub = $this->createStub(PackageVerifier::class);
        $stub->method('checkAll')->willReturn([
            new CheckResult('composer', 'acme/perfect-pkg', 'passed', 'OK'),
        ]);
        $this->app->instance(PackageVerifier::class, $stub);
        $this->app->forgetInstance(ReleaseChecker::class);

        // Re-resolve checker with stub
        $this->checker = $this->app->make(ReleaseChecker::class);

        Process::fake(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
            if (str_contains($cmd, 'status')) {
                return Process::result('');
            }
            if (str_contains($cmd, 'tag')) {
                return Process::result("v1.0.0\n");
            }
            if (str_contains($cmd, 'rev-list') && str_contains($cmd, '--count')) {
                return Process::result("0\n");
            }

            return Process::result('OK');
        });

        $result = $this->checker->check('acme/perfect-pkg', fast: true);

        $this->assertSame('READY', $result['verdict']);
        $this->assertSame('v1.0.0', $result['latest_tag']);
    }

    public function test_release_checker_returns_action_required_when_no_audit_file_exists(): void
    {
        Workspace::add('packages');
        $dir = $this->tempDir.'/packages/acme/my-pkg';
        File::ensureDirectoryExists($dir.'/.git');
        File::put($dir.'/composer.json', json_encode(['name' => 'acme/my-pkg']));
        File::put($dir.'/.gitattributes', "/tests export-ignore\n");
        File::put($dir.'/README.md', "# Perfect Pkg\n\n## Requirements\n\n## Installation\n\n## Usage\n\n## Testing\n\n## License\n");
        Workspace::sync();

        $stub = $this->createStub(PackageVerifier::class);
        $stub->method('checkAll')->willReturn([
            new CheckResult('composer', 'acme/my-pkg', 'passed', 'OK'),
        ]);
        $this->app->instance(PackageVerifier::class, $stub);
        $this->app->forgetInstance(ReleaseChecker::class);

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

        $checker = app(ReleaseChecker::class);
        $result = $checker->check('packages/acme/my-pkg', fast: true);

        $this->assertNotSame('READY', $result['verdict']);
        $this->assertSame('ACTION_REQUIRED', $result['verdict']);
    }
}
