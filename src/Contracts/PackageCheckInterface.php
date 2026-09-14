<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Contracts;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\CheckResult;

interface PackageCheckInterface
{
    /**
     * Unique identifier of the check (e.g. 'composer', 'pint', 'phpstan', 'tests', 'isolated', 'git_cleanliness', 'readme', 'export_ignore').
     */
    public function name(): string;

    /**
     * Human-readable label for terminal output and reports.
     */
    public function title(): string;

    /**
     * Tiers where this check is executed ('quick', 'deep', 'audit').
     *
     * @return array<int, string>
     */
    public function tiers(): array;

    /**
     * Determine if this check applies to the specified package path.
     *
     * @param  array<string, mixed>  $options
     */
    public function isApplicable(string $packagePath, ?string $packageName = null, array $options = []): bool;

    /**
     * Execute the check and return a typed CheckResult.
     *
     * @param  array<string, mixed>  $options  Execution options (e.g. fix, dry-run, environment)
     */
    public function execute(string $packagePath, ?string $packageName = null, array $options = []): CheckResult;
}
