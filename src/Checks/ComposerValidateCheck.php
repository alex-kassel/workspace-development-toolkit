<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Checks;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\CheckResult;
use Illuminate\Support\Facades\File;

class ComposerValidateCheck extends BaseCheck
{
    public function name(): string
    {
        return 'composer';
    }

    public function title(): string
    {
        return 'Composer Validation';
    }

    public function tiers(): array
    {
        return ['quick', 'deep', 'audit'];
    }

    public function execute(string $packagePath, ?string $packageName = null, array $options = []): CheckResult
    {
        $absPackagePath = $this->normalizePath($packagePath);
        $packageName ??= $this->resolvePackageName($absPackagePath);

        $composerPath = $absPackagePath.DIRECTORY_SEPARATOR.'composer.json';
        if (! File::exists($composerPath)) {
            return new CheckResult(
                check: $this->name(),
                package: $packageName,
                status: 'failed',
                output: 'composer.json not found in package directory.',
                durationSeconds: 0.0,
            );
        }

        $relComposer = str_replace('\\', '/', ltrim(str_replace(base_path(), '', $composerPath), '/\\'));

        return $this->runCommand(
            ['composer', 'validate', '--strict', $relComposer],
            base_path(),
            $this->name(),
            $packageName
        );
    }
}
