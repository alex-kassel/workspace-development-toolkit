<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Unit;

use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerDiagnosticService;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;

class ComposerDiagnosticServiceTest extends TestCase
{
    protected ComposerDiagnosticService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ComposerDiagnosticService;
    }

    public function test_diagnoses_minimum_stability_failure(): void
    {
        $output = <<<'OUTPUT'
./composer.json has been updated
Running composer update alex-kassel/car-subscription
Loading composer repositories with package information
Updating dependencies
Your requirements could not be resolved to an installable set of packages.

  Problem 1
    - alex-kassel/car-subscription dev-main requires alex-kassel/scraper-core dev-main -> could not be found in any version, but it does match your minimum-stability.
    - alex-kassel/car-subscription dev-main requires alex-kassel/laravel-domain-core dev-main -> could not be found in any version, but it does match your minimum-stability.
OUTPUT;

        $result = $this->service->diagnoseInstallFailure($output, 'alex-kassel/car-subscription', true);

        $this->assertSame('minimum_stability', $result->type);
        $this->assertTrue($result->isStabilityIssue());
        $this->assertFalse($result->isNetworkIssue());
        $this->assertStringContainsString('Stability Mismatch', $result->title);
        $this->assertStringContainsString('composer config minimum-stability dev', implode("\n", $result->actionableSteps));
        $this->assertStringContainsString('composer config prefer-stable true', implode("\n", $result->actionableSteps));
    }

    public function test_diagnoses_network_connectivity_failure(): void
    {
        $output = 'Could not resolve host: repo.packagist.org; curl error 6';
        $result = $this->service->diagnoseInstallFailure($output, 'vendor/package', true);

        $this->assertSame('network_offline', $result->type);
        $this->assertTrue($result->isNetworkIssue());
        $this->assertFalse($result->isStabilityIssue());
        $this->assertStringContainsString('Network Issue', $result->title);
        $this->assertStringContainsString('COMPOSER_DISABLE_NETWORK=1', implode("\n", $result->actionableSteps));
    }

    public function test_diagnoses_php_version_incompatibility(): void
    {
        $output = 'alex-kassel/car-subscription dev-main requires php ^8.4 but your php version (8.3.6) does not satisfy that requirement.';
        $result = $this->service->diagnoseInstallFailure($output, 'alex-kassel/car-subscription', true);

        $this->assertSame('php_version', $result->type);
        $this->assertFalse($result->isStabilityIssue());
        $this->assertStringContainsString('PHP Version Incompatibility', $result->title);
        $this->assertStringContainsString('php -v', implode("\n", $result->actionableSteps));
    }

    public function test_diagnoses_missing_php_extension(): void
    {
        $output = 'Problem 1 - Root composer.json requires ext-gd * but it is missing from your system.';
        $result = $this->service->diagnoseInstallFailure($output, 'vendor/package', true);

        $this->assertSame('extension_missing', $result->type);
        $this->assertStringContainsString('Missing PHP Extension', $result->title);
        $this->assertStringContainsString('php -m', implode("\n", $result->actionableSteps));
    }

    public function test_diagnoses_dependency_conflict_for_existing_local_package(): void
    {
        $output = 'Problem 1 - Root composer.json requires foo/bar 1.0 -> satisfiable by foo/bar[1.0]. Only one of these can be installed at a time.';
        $result = $this->service->diagnoseInstallFailure($output, 'acme/existing-pkg', true);

        $this->assertSame('dependency_conflict', $result->type);
        $this->assertStringContainsString('Dependency Resolution Conflict', $result->title);
        $this->assertStringContainsString('composer require acme/existing-pkg:@dev -W', implode("\n", $result->actionableSteps));
        $this->assertStringNotContainsString('package:make', implode("\n", $result->actionableSteps));
    }

    public function test_diagnoses_not_found_for_missing_local_package(): void
    {
        $output = 'Could not find a matching version of package acme/ghost-pkg.';
        $result = $this->service->diagnoseInstallFailure($output, 'acme/ghost-pkg', false);

        $this->assertSame('not_found', $result->type);
        $this->assertStringContainsString('Not Found', $result->title);
        $this->assertStringContainsString('php artisan package:make acme/ghost-pkg', implode("\n", $result->actionableSteps));
    }
}
