<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Checks;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\CheckResult;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\BinaryResolver;

class PintStyleCheck extends BaseCheck
{
    public function __construct(
        protected BinaryResolver $binaryResolver
    ) {}

    public function name(): string
    {
        return 'pint';
    }

    public function title(): string
    {
        return 'Pint Code Style';
    }

    public function tiers(): array
    {
        return ['quick', 'deep', 'audit'];
    }

    public function execute(string $packagePath, ?string $packageName = null, array $options = []): CheckResult
    {
        $absPackagePath = $this->normalizePath($packagePath);
        $packageName ??= $this->resolvePackageName($absPackagePath);

        $pintBin = $this->binaryResolver->resolve('pint');
        if ($pintBin === null) {
            return new CheckResult(
                check: $this->name(),
                package: $packageName,
                status: 'failed',
                output: 'Pint binary not found in vendor/bin. Run "composer require --dev laravel/pint" on the host.',
                durationSeconds: 0.0,
            );
        }

        $fix = (bool) ($options['fix'] ?? true);
        $relPath = str_replace('\\', '/', ltrim(str_replace(base_path(), '', $absPackagePath), '/\\'));
        $command = [$pintBin, $relPath];

        if (! $fix) {
            $command[] = '--test';
        }

        return $this->runCommand(
            $command,
            base_path(),
            $this->name(),
            $packageName
        );
    }
}
