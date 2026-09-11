<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\CheckResult;
use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\PackageVerifier;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class PackageReleaseCheckCommandTest extends TestCase
{
    public function test_package_release_check_reports_ready_for_perfect_package(): void
    {
        Workspace::add('packages', null, true);
        $dir = $this->tempDir.'/packages/acme/release-pkg';
        File::ensureDirectoryExists($dir.'/.git');
        File::put($dir.'/composer.json', json_encode(['name' => 'acme/release-pkg']));
        File::put($dir.'/.gitattributes', "/tests export-ignore\n");
        File::put($dir.'/README.md', "# Release Pkg\n\n## Requirements\n\n## Installation\n\n## Usage\n\n## Testing\n\n## License\n");

        $this->app->bind(PackageVerifier::class, function () {
            $mock = $this->createMock(PackageVerifier::class);
            $mock->method('checkAll')->willReturn([
                new CheckResult('composer', 'acme/release-pkg', 'passed', 'OK'),
            ]);

            return $mock;
        });

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

        $this->artisan('package:release-check', [
            'name' => 'acme/release-pkg',
            '--fast' => true,
        ])
            ->expectsOutputToContain('READY TO PUBLISH')
            ->assertSuccessful();
    }

    public function test_package_release_check_outputs_json(): void
    {
        Workspace::add('packages', null, true);
        $dir = $this->tempDir.'/packages/acme/json-rel-pkg';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/composer.json', json_encode(['name' => 'acme/json-rel-pkg']));

        $this->artisan('package:release-check', [
            'name' => 'acme/json-rel-pkg',
            '--fast' => true,
            '--json' => true,
        ])
            ->expectsOutputToContain('"BLOCKED"')
            ->assertFailed();
    }
}
