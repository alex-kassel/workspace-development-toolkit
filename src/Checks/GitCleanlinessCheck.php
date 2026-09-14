<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Checks;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\CheckResult;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\GitInspector;
use Illuminate\Support\Facades\Process;

class GitCleanlinessCheck extends BaseCheck
{
    public function __construct(
        protected GitInspector $gitInspector
    ) {}

    public function name(): string
    {
        return 'git_cleanliness';
    }

    public function title(): string
    {
        return 'Git Repository Cleanliness';
    }

    public function tiers(): array
    {
        return ['audit'];
    }

    public function execute(string $packagePath, ?string $packageName = null, array $options = []): CheckResult
    {
        $startTime = microtime(true);
        $absPackagePath = $this->normalizePath($packagePath);
        $packageName ??= $this->resolvePackageName($absPackagePath);

        if (! $this->gitInspector->hasGitRepository($absPackagePath)) {
            return new CheckResult(
                check: $this->name(),
                package: $packageName,
                status: 'failed',
                output: 'Not an independent git repository (.git directory missing in package root).',
                durationSeconds: (float) round(microtime(true) - $startTime, 3),
            );
        }

        $statusProcess = Process::path($absPackagePath)->run(['git', 'status', '--porcelain']);
        if (! $statusProcess->successful()) {
            return new CheckResult(
                check: $this->name(),
                package: $packageName,
                status: 'failed',
                output: 'Failed to query git status: '.$statusProcess->errorOutput(),
                durationSeconds: (float) round(microtime(true) - $startTime, 3),
            );
        }

        $statusOutput = trim($statusProcess->output());
        if ($statusOutput !== '') {
            $lines = array_filter(explode("\n", str_replace("\r", '', $statusOutput)));
            $unrelated = array_filter($lines, function ($line) {
                $file = trim(substr($line, 3));

                return $file !== 'AUDIT.json';
            });

            if (! empty($unrelated)) {
                return new CheckResult(
                    check: $this->name(),
                    package: $packageName,
                    status: 'failed',
                    output: "Working tree is dirty. Uncommitted changes detected:\n".implode("\n", $unrelated),
                    durationSeconds: (float) round(microtime(true) - $startTime, 3),
                );
            }
        }

        return new CheckResult(
            check: $this->name(),
            package: $packageName,
            status: 'passed',
            output: 'Git repository is clean. No uncommitted changes.',
            durationSeconds: (float) round(microtime(true) - $startTime, 3),
        );
    }
}
