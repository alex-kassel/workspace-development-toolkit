<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

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

        if ($package === '' && $this->input->isInteractive()) {
            $defaultPrompt = 'Please enter the package name (format: vendor/package, e.g. acme/my-pkg):';
            $package = trim((string) $this->ask($prompt ?? $defaultPrompt));
        }

        if ($package === '') {
            $this->error('Package name is required.');
            $this->line("  <comment>Usage:</comment>   php artisan {$this->getName()} <vendor/package>");
            $this->line("  <comment>Example:</comment> php artisan {$this->getName()} acme/my-pkg");

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
        $normalizedInput = $this->sanitizeInput($rawPackage);

        try {
            $canonical = $this->workspace->resolveCanonicalPackageName($normalizedInput);
        } catch (WorkspaceException $e) {
            $this->handleWorkspaceException($e);

            return null;
        }

        $validation = $this->workspace->validatePackageName($canonical);
        if (! $validation['isValid']) {
            $this->error($validation['error'] ?? "Invalid package name [{$rawPackage}].");
            if ($validation['suggestion'] !== null) {
                $this->line('  <comment>How to fix:</comment> Did you mean:');
                $this->line("  <info>php artisan {$this->getName()} {$validation['suggestion']}</info>");
            } else {
                $this->line('  <comment>How to fix:</comment> Specify the full package name:');
                $this->line("  <info>php artisan {$this->getName()} my-vendor/my-package</info>");
                $this->line('  Or check registered workspaces: <info>php artisan workspace:list</info>');
            }

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
