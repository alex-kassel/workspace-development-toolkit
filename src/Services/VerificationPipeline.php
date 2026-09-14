<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\Contracts\PackageCheckInterface;
use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\CheckResult;
use InvalidArgumentException;

class VerificationPipeline
{
    /**
     * Registered checks indexed by check name.
     *
     * @var array<string, PackageCheckInterface>
     */
    protected array $checks = [];

    /**
     * Aliases mapping alternate names to primary check names.
     *
     * @var array<string, string>
     */
    protected array $aliases = [];

    /**
     * @param  array<int, PackageCheckInterface>  $checks
     */
    public function __construct(array $checks = [])
    {
        foreach ($checks as $check) {
            $this->registerCheck($check);
        }

        $this->registerConfiguredChecks();
    }

    /**
     * Register a check into the pipeline with optional aliases.
     *
     * @param  array<int, string>  $aliases
     */
    public function registerCheck(PackageCheckInterface $check, array $aliases = []): self
    {
        $this->checks[$check->name()] = $check;
        foreach ($aliases as $alias) {
            $this->aliases[$alias] = $check->name();
        }

        return $this;
    }

    /**
     * Get all registered checks.
     *
     * @return array<string, PackageCheckInterface>
     */
    public function getChecks(): array
    {
        return $this->checks;
    }

    /**
     * Resolve alias to primary check name.
     */
    public function resolveCheckName(string $name): string
    {
        return $this->aliases[$name] ?? $name;
    }

    /**
     * Get checks applicable to a specific tier, optionally filtered by $only names.
     *
     * @param  array<int, string>  $only
     * @return array<string, PackageCheckInterface>
     *
     * @throws InvalidArgumentException
     */
    public function getChecksForTier(string $tier, array $only = []): array
    {
        $allAllowedNames = array_merge(array_keys($this->checks), array_keys($this->aliases));

        if (! empty($only)) {
            $only = array_map('trim', $only);
            $invalid = array_diff($only, $allAllowedNames);
            if (! empty($invalid)) {
                throw new InvalidArgumentException(
                    'Unknown check(s): '.implode(', ', $invalid).'. Allowed checks: '.implode(', ', array_keys($this->checks))
                );
            }
        }

        $resolvedOnly = array_map(fn ($n) => $this->resolveCheckName($n), $only);

        $applicable = [];
        foreach ($this->checks as $name => $check) {
            if (! empty($resolvedOnly)) {
                if (in_array($name, $resolvedOnly, true)) {
                    $applicable[$name] = $check;
                }

                continue;
            }

            if (in_array($tier, $check->tiers(), true)) {
                $applicable[$name] = $check;
            }
        }

        return $applicable;
    }

    /**
     * Get a registered check by name or alias.
     */
    public function getCheck(string $name): ?PackageCheckInterface
    {
        $resolved = $this->resolveCheckName($name);

        return $this->checks[$resolved] ?? null;
    }

    /**
     * Run checks for a given package path and return results.
     *
     * @param  array<int, string>  $only
     * @param  array<string, mixed>  $options
     * @return array<string, CheckResult>
     */
    public function run(
        string $packagePath,
        ?string $packageName = null,
        string $tier = 'deep',
        array $only = [],
        array $options = []
    ): array {
        $checksToRun = $this->getChecksForTier($tier, $only);

        if (! empty($options['isolated']) && ! isset($checksToRun['isolated']) && empty($only)) {
            if (isset($this->checks['isolated'])) {
                $checksToRun['isolated'] = $this->checks['isolated'];
            }
        }

        $results = [];
        foreach ($checksToRun as $name => $check) {
            if (! $check->isApplicable($packagePath, $packageName, $options)) {
                continue;
            }

            try {
                $results[$name] = $check->execute($packagePath, $packageName, $options);
            } catch (\Throwable $e) {
                $results[$name] = new CheckResult(
                    check: $name,
                    package: $packageName ?? $packagePath,
                    status: 'failed',
                    output: "Check failed with exception: {$e->getMessage()}",
                    durationSeconds: 0.0
                );
            }
        }

        return $results;
    }

    /**
     * Run a single check by name.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidArgumentException
     */
    public function runSingle(
        string $checkName,
        string $packagePath,
        ?string $packageName = null,
        array $options = []
    ): CheckResult {
        $resolved = $this->resolveCheckName($checkName);
        if (! isset($this->checks[$resolved])) {
            throw new InvalidArgumentException("Check [{$checkName}] is not registered in the verification pipeline.");
        }

        return $this->checks[$resolved]->execute($packagePath, $packageName, $options);
    }

    /**
     * Register any checks specified in the host config.
     */
    protected function registerConfiguredChecks(): void
    {
        $configured = config('workspace.checks', []);
        if (! is_array($configured)) {
            return;
        }

        foreach ($configured as $checkClass) {
            if (is_string($checkClass) && class_exists($checkClass)) {
                $instance = app($checkClass);
                if ($instance instanceof PackageCheckInterface) {
                    $this->registerCheck($instance);
                }
            }
        }
    }
}
