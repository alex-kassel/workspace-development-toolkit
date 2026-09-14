<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Enums\DiagnosticSeverity;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;

abstract class BasePackageCommand extends BaseCommand
{
    /**
     * Retrieve and validate the required package argument.
     * Zero-ambiguity: provides concrete format example in prompt and error.
     */
    protected function getRequiredPackage(?string $prompt = null): ?string
    {
        $package = trim((string) $this->argument('package'));

        if ($package === '' && $this->input->isInteractive() && @stream_isatty(STDIN)) {
            $defaultPrompt = 'Please enter the package name (format: vendor/package, e.g. acme/my-pkg):';
            $package = trim((string) $this->ask($prompt ?? $defaultPrompt));
        }

        if ($package === '') {
            $this->dispatchDiagnostic(
                code: 'CMD_ARGUMENT_REQUIRED',
                message: 'Package name argument is required.',
                severity: DiagnosticSeverity::Error,
                context: [
                    'Expected format' => 'vendor/package (e.g. acme/my-pkg)',
                ],
                remediationSteps: [
                    "php artisan {$this->getName()} acme/my-pkg",
                ],
                agentGuidance: "Provide the target package as an argument: 'php artisan {$this->getName()} <vendor/package>'."
            );

            return null;
        }

        return $this->normalizePackageInput($package);
    }

    /**
     * Resolve and validate package name from raw user input.
     * Outputs standardized error messages and suggestions if invalid.
     */
    protected function resolveAndValidatePackage(string $rawPackage): ?string
    {
        if (trim($rawPackage) === '') {
            return $this->getRequiredPackage();
        }

        $normalizedInput = $this->sanitizeInput($rawPackage);

        try {
            $canonical = $this->workspace->resolveCanonicalPackageName($normalizedInput);
        } catch (WorkspaceException $e) {
            $this->handleWorkspaceException($e);

            return null;
        }

        $validation = $this->workspace->validatePackageName($canonical);
        if (! $validation['isValid']) {
            $suggestion = $validation['suggestion'] ?? null;
            $remediation = $suggestion !== null
                ? ["php artisan {$this->getName()} {$suggestion}"]
                : [
                    "php artisan {$this->getName()} my-vendor/my-package",
                    'php artisan workspace:list',
                ];

            $this->dispatchDiagnostic(
                code: 'CMD_INVALID_ARGUMENT',
                message: $validation['error'] ?? "Invalid package name [{$rawPackage}].",
                severity: DiagnosticSeverity::Error,
                remediationSteps: $remediation,
                agentGuidance: 'Package name must be in vendor/package format using lowercase alphanumeric characters and hyphens.'
            );

            return null;
        }

        $package = $validation['fullName'];

        if ($package !== $rawPackage) {
            $this->line("  <comment>Notice:</comment> Resolved package [{$rawPackage}] to Composer package [{$package}].");
        }

        return $package;
    }

    /**
     * Standardized warning renderer when a chosen alias or name conflicts with another package in the workspace.
     */
    protected function warnIfDuplicateAlias(string $alias, string $currentPath, ?string $packageName = null): void
    {
        $duplicates = $this->workspace->findDuplicateAliases($alias, $currentPath);
        if (empty($duplicates)) {
            return;
        }

        $this->newLine();
        $this->warn("Notice: The alias/name [{$alias}] is also used by another package:");
        foreach ($duplicates as $duplicate) {
            $this->line("  • {$duplicate}");
        }
        $this->newLine();
        $this->line("  <comment>Hint:</comment> Both packages will work normally in Composer, but resolving by short name '{$alias}' will be ambiguous.");
        $this->line('  If you wish to differentiate them, you can assign a unique alias:');
        $target = $packageName ?? $this->workspace->resolveCanonicalPackageName($currentPath);
        $this->line("  <info>php artisan package:alias {$target} UniqueAlias</info>");
    }

    /**
     * Normalize a raw package input string (e.g. windows slashes, whitespace).
     */
    protected function normalizePackageInput(string $input): string
    {
        return $this->sanitizeInput($input);
    }
}
