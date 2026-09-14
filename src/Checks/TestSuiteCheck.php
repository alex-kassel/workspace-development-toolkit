<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Checks;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\CheckResult;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\BinaryResolver;
use Illuminate\Support\Facades\File;

class TestSuiteCheck extends BaseCheck
{
    public function __construct(
        protected BinaryResolver $binaryResolver
    ) {}

    public function name(): string
    {
        return 'tests';
    }

    public function title(): string
    {
        return 'PHPUnit / Pest Test Suite';
    }

    public function tiers(): array
    {
        return ['deep', 'audit'];
    }

    public function isApplicable(string $packagePath, ?string $packageName = null, array $options = []): bool
    {
        $absPackagePath = $this->normalizePath($packagePath);

        return File::exists($absPackagePath.DIRECTORY_SEPARATOR.'phpunit.xml')
            || File::exists($absPackagePath.DIRECTORY_SEPARATOR.'phpunit.xml.dist');
    }

    public function execute(string $packagePath, ?string $packageName = null, array $options = []): CheckResult
    {
        $absPackagePath = $this->normalizePath($packagePath);
        $packageName ??= $this->resolvePackageName($absPackagePath);

        $phpunitXml = null;
        foreach (['phpunit.xml', 'phpunit.xml.dist'] as $cfg) {
            if (File::exists($absPackagePath.DIRECTORY_SEPARATOR.$cfg)) {
                $phpunitXml = $cfg;
                break;
            }
        }

        if ($phpunitXml === null) {
            return new CheckResult(
                check: $this->name(),
                package: $packageName,
                status: 'skipped',
                output: 'No phpunit.xml or phpunit.xml.dist found in package directory.',
                durationSeconds: 0.0,
            );
        }

        $relPath = str_replace('\\', '/', ltrim(str_replace(base_path(), '', $absPackagePath), '/\\'));
        $xmlRel = $relPath.'/'.$phpunitXml;

        $pestBin = $this->binaryResolver->resolve('pest');
        $phpunitBin = $this->binaryResolver->resolve('phpunit');
        $isPest = File::exists($absPackagePath.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Pest.php') && $pestBin !== null;

        if ($isPest) {
            $command = [$pestBin, '-c', $xmlRel];
        } elseif ($phpunitBin !== null) {
            $command = [$phpunitBin, '-c', $xmlRel];
        } else {
            $artisan = base_path('artisan');
            if (File::exists($artisan)) {
                $command = [PHP_BINARY, 'artisan', 'test', '-c', $xmlRel];
            } else {
                return new CheckResult(
                    check: $this->name(),
                    package: $packageName,
                    status: 'failed',
                    output: 'No test runner (phpunit or pest) found in vendor/bin. Run "composer require --dev phpunit/phpunit" on the host.',
                    durationSeconds: 0.0,
                );
            }
        }

        $command[] = '--fail-on-empty-test-suite';

        $env = [
            'APP_ENV' => 'testing',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
        ];

        return $this->runCommand(
            $command,
            base_path(),
            $this->name(),
            $packageName,
            $env
        );
    }
}
