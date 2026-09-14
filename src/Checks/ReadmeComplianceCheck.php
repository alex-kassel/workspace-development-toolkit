<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Checks;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\CheckResult;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ReadmeValidator;

class ReadmeComplianceCheck extends BaseCheck
{
    public function __construct(
        protected ReadmeValidator $readmeValidator
    ) {}

    public function name(): string
    {
        return 'readme';
    }

    public function title(): string
    {
        return 'README Compliance';
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

        $readmeResult = $this->readmeValidator->validate($absPackagePath);

        $status = $readmeResult['status'] === 'passed' ? 'passed' : 'failed';
        $failedChecks = array_filter($readmeResult['checks'], fn ($c) => $c['status'] === 'failed');
        $output = $status === 'passed'
            ? 'README matches configured standard sections.'
            : "README standard violations found:\n".implode("\n", array_map(fn ($c) => $c['message'], $failedChecks));

        return new CheckResult(
            check: $this->name(),
            package: $packageName,
            status: $status,
            output: $output,
            durationSeconds: (float) round(microtime(true) - $startTime, 3),
        );
    }
}
