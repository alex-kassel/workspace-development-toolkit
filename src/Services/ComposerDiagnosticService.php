<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\ComposerDiagnosticResult;

final readonly class ComposerDiagnosticService
{
    /**
     * Diagnose a Composer installation or requirement failure and produce actionable steps.
     */
    public function diagnoseInstallFailure(
        string $rawOutput,
        string $packageName,
        bool $packageExistsLocally = true
    ): ComposerDiagnosticResult {
        $trimmed = trim($rawOutput);

        // 1. Minimum stability mismatch
        if (str_contains($trimmed, 'does not match your minimum-stability')
            || str_contains($trimmed, 'minimum-stability')) {
            return new ComposerDiagnosticResult(
                type: 'minimum_stability',
                title: 'Stability Mismatch: Transitive Dependency Requires Dev Stability',
                explanation: "Package [{$packageName}] or one of its dependencies requires development stability (e.g. dev-main). Your root composer.json currently has \"minimum-stability\": \"stable\", which blocks Composer from resolving local development packages.",
                actionableSteps: [
                    'Enable development stability in root composer.json: composer config minimum-stability dev',
                    'Ensure stable packages are still preferred: composer config prefer-stable true',
                    'Or synchronize workspace configuration: php artisan workspace:sync',
                ],
                rawOutput: $trimmed,
            );
        }

        // 2. Network / DNS / offline issue
        if (str_contains($trimmed, 'Could not resolve host')
            || str_contains($trimmed, 'curl error 6')
            || str_contains($trimmed, 'you are offline')
            || str_contains($trimmed, 'Connection refused')) {
            return new ComposerDiagnosticResult(
                type: 'network_offline',
                title: 'Network Issue: Composer Failed to Connect to Package Registry',
                explanation: 'Composer could not reach Packagist or a remote repository to download required package metadata.',
                actionableSteps: [
                    'Check your internet connection and DNS configuration',
                    'If working offline with cached packages, re-run with: COMPOSER_DISABLE_NETWORK=1 php artisan package:install '.$packageName,
                ],
                rawOutput: $trimmed,
            );
        }

        // 3. PHP version mismatch
        if (str_contains($trimmed, 'requires php') && str_contains($trimmed, 'does not satisfy')) {
            return new ComposerDiagnosticResult(
                type: 'php_version',
                title: 'PHP Version Incompatibility',
                explanation: "The requirements of [{$packageName}] or one of its dependencies do not match your current PHP CLI runtime.",
                actionableSteps: [
                    'Verify your active PHP version: php -v',
                    "Check the PHP requirement in the package's composer.json",
                ],
                rawOutput: $trimmed,
            );
        }

        // 4. Missing PHP extension
        if (str_contains($trimmed, 'requires ext-') && (str_contains($trimmed, 'missing') || str_contains($trimmed, 'not loaded'))) {
            return new ComposerDiagnosticResult(
                type: 'extension_missing',
                title: 'Missing PHP Extension',
                explanation: "A required PHP extension for [{$packageName}] is not installed or enabled in your active PHP runtime.",
                actionableSteps: [
                    'Check currently loaded PHP extensions: php -m',
                    'Install or enable the missing extension in your php.ini configuration',
                ],
                rawOutput: $trimmed,
            );
        }

        // 5. Generic dependency resolution failure
        if ($packageExistsLocally) {
            return new ComposerDiagnosticResult(
                type: 'dependency_conflict',
                title: 'Dependency Resolution Conflict',
                explanation: "Composer could not resolve an installable set of dependencies for [{$packageName}].",
                actionableSteps: [
                    "Inspect the \"require\" and \"require-dev\" sections in [{$packageName}]'s composer.json",
                    "Try installing with dependency upgrades: composer require {$packageName}:@dev -W",
                ],
                rawOutput: $trimmed,
            );
        }

        return new ComposerDiagnosticResult(
            type: 'not_found',
            title: "Package [{$packageName}] Not Found",
            explanation: "Composer could not find package [{$packageName}] in any registered workspace or remote repository.",
            actionableSteps: [
                "If this is a new local package, create it first: php artisan package:make {$packageName}",
                'Check all registered workspaces and packages: php artisan workspace:list',
            ],
            rawOutput: $trimmed,
        );
    }
}
