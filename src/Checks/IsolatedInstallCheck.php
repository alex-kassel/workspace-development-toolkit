<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Checks;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\CheckResult;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\IsolatedPackageVerifier;

class IsolatedInstallCheck extends BaseCheck
{
    public function __construct(
        protected IsolatedPackageVerifier $isolatedVerifier
    ) {}

    public function name(): string
    {
        return 'isolated';
    }

    public function title(): string
    {
        return 'Isolated Package Installation';
    }

    public function tiers(): array
    {
        return ['audit'];
    }

    public function execute(string $packagePath, ?string $packageName = null, array $options = []): CheckResult
    {
        $absPackagePath = $this->normalizePath($packagePath);
        $packageName ??= $this->resolvePackageName($absPackagePath);

        return $this->isolatedVerifier->verify($absPackagePath, $packageName);
    }
}
