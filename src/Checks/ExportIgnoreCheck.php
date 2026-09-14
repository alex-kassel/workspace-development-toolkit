<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Checks;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\CheckResult;
use Illuminate\Support\Facades\File;

class ExportIgnoreCheck extends BaseCheck
{
    public function name(): string
    {
        return 'export_ignore';
    }

    public function title(): string
    {
        return 'Git Attributes Export-Ignore';
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

        $gitattrPath = $absPackagePath.DIRECTORY_SEPARATOR.'.gitattributes';

        if (! File::exists($gitattrPath)) {
            return new CheckResult(
                check: $this->name(),
                package: $packageName,
                status: 'failed',
                output: 'Missing .gitattributes file in package root.',
                durationSeconds: (float) round(microtime(true) - $startTime, 3),
            );
        }

        $content = File::get($gitattrPath);
        if (! str_contains($content, 'export-ignore')) {
            return new CheckResult(
                check: $this->name(),
                package: $packageName,
                status: 'failed',
                output: '.gitattributes exists but contains no export-ignore directives.',
                durationSeconds: (float) round(microtime(true) - $startTime, 3),
            );
        }

        return new CheckResult(
            check: $this->name(),
            package: $packageName,
            status: 'passed',
            output: '.gitattributes contains valid export-ignore directives.',
            durationSeconds: (float) round(microtime(true) - $startTime, 3),
        );
    }
}
