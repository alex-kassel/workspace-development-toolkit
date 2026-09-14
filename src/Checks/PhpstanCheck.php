<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Checks;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\CheckResult;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\BinaryResolver;
use Illuminate\Support\Facades\File;

class PhpstanCheck extends BaseCheck
{
    public const DEFAULT_PHPSTAN_LEVEL = 8;

    public function __construct(
        protected BinaryResolver $binaryResolver
    ) {}

    public function name(): string
    {
        return 'phpstan';
    }

    public function title(): string
    {
        return 'PHPStan Static Analysis';
    }

    public function tiers(): array
    {
        return ['deep', 'audit'];
    }

    public function isApplicable(string $packagePath, ?string $packageName = null, array $options = []): bool
    {
        $absPackagePath = $this->normalizePath($packagePath);

        return File::isDirectory($absPackagePath.DIRECTORY_SEPARATOR.'src');
    }

    public function execute(string $packagePath, ?string $packageName = null, array $options = []): CheckResult
    {
        $absPackagePath = $this->normalizePath($packagePath);
        $packageName ??= $this->resolvePackageName($absPackagePath);

        $phpstanBin = $this->binaryResolver->resolve('phpstan');
        if ($phpstanBin === null) {
            return new CheckResult(
                check: $this->name(),
                package: $packageName,
                status: 'failed',
                output: 'PHPStan binary not found in vendor/bin. Run "composer require --dev phpstan/phpstan" on the host.',
                durationSeconds: 0.0,
            );
        }

        if (! $this->isApplicable($absPackagePath)) {
            return new CheckResult(
                check: $this->name(),
                package: $packageName,
                status: 'skipped',
                output: 'No src/ directory found in package.',
                durationSeconds: 0.0,
            );
        }

        $relPath = str_replace('\\', '/', ltrim(str_replace(base_path(), '', $absPackagePath), '/\\'));
        $neonConfig = null;
        foreach (['phpstan.neon', 'phpstan.neon.dist'] as $cfg) {
            if (File::exists($absPackagePath.DIRECTORY_SEPARATOR.$cfg)) {
                $neonConfig = $relPath.'/'.$cfg;
                break;
            }
        }

        $command = [$phpstanBin, 'analyse', '--debug'];
        if ($neonConfig !== null) {
            $command[] = '--configuration='.$neonConfig;
        } else {
            $command[] = $relPath.'/src';
            $command[] = '--level='.self::DEFAULT_PHPSTAN_LEVEL;
        }
        $command[] = '--memory-limit=1G';

        return $this->runCommand(
            $command,
            base_path(),
            $this->name(),
            $packageName
        );
    }
}
