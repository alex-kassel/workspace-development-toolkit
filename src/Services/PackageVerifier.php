<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\CheckResult;

class PackageVerifier
{
    public function __construct(
        protected readonly VerificationPipeline $pipeline
    ) {}

    /**
     * Run all selected checks for a package via the pipeline.
     *
     * @param  array<int, string>  $only
     * @return array<int, CheckResult>
     */
    public function checkAll(
        string $packagePath,
        string|array|null $packageName = null,
        string|array $tier = 'deep',
        array $only = [],
        bool $fix = true,
        bool $isolated = false,
        bool $withWorkspaceDeps = false,
    ): array {
        if ($packageName === 'quick' || $packageName === 'deep') {
            if (is_array($tier)) {
                $isolated = (bool) $fix;
                $fix = (bool) ($only ?: false);
                $only = $tier;
            }
            $tier = $packageName;
            $packageName = null;
        } elseif (is_array($packageName)) {
            $only = $packageName;
            $packageName = null;
        }

        if (is_array($tier)) {
            $isolated = (bool) $fix;
            $fix = (bool) ($only ?: false);
            $only = $tier;
            $tier = 'deep';
        }

        $results = $this->pipeline->run(
            $packagePath,
            $packageName,
            $tier,
            $only,
            [
                'fix' => $fix,
                'isolated' => $isolated,
                'withWorkspaceDeps' => $withWorkspaceDeps,
            ]
        );

        return array_values($results);
    }

    /**
     * Run Composer validation check.
     */
    public function checkComposer(string $packagePath, ?string $packageName = null): CheckResult
    {
        return $this->pipeline->runSingle('composer', $packagePath, $packageName);
    }

    /**
     * Run Pint code style check.
     */
    public function checkPint(string $packagePath, ?string $packageName = null, bool $fix = true): CheckResult
    {
        return $this->pipeline->runSingle('pint', $packagePath, $packageName, ['fix' => $fix]);
    }

    /**
     * Run PHPStan static analysis check.
     */
    public function checkPhpstan(string $packagePath, ?string $packageName = null): CheckResult
    {
        return $this->pipeline->runSingle('phpstan', $packagePath, $packageName);
    }

    /**
     * Run automated test suite check (PHPUnit / Pest).
     */
    public function checkTests(string $packagePath, ?string $packageName = null): CheckResult
    {
        return $this->pipeline->runSingle('tests', $packagePath, $packageName);
    }

    /**
     * Run isolated package verification in a clean temporary directory.
     */
    public function checkIsolated(string $packagePath, ?string $packageName = null): CheckResult
    {
        return $this->pipeline->runSingle('isolated', $packagePath, $packageName);
    }

    /**
     * Run checks across multiple packages.
     *
     * @param  array<string, string>  $packagesToVerify  [packageName => packagePath]
     * @param  array<int, string>  $only
     * @return array<string, array<int, CheckResult>>
     */
    public function checkAllPackages(
        array $packagesToVerify,
        string $tier = 'deep',
        array $only = [],
        bool $fix = true,
        bool $isolated = false,
        bool $withWorkspaceDeps = false
    ): array {
        $allResults = [];
        foreach ($packagesToVerify as $packageName => $packagePath) {
            $allResults[$packagePath] = $this->checkAll(
                packagePath: $packagePath,
                packageName: (string) $packageName,
                tier: $tier,
                only: $only,
                fix: $fix,
                isolated: $isolated,
                withWorkspaceDeps: $withWorkspaceDeps
            );
        }

        return $allResults;
    }
}
