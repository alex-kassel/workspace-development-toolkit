<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Unit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\Contracts\PackageCheckInterface;
use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\CheckResult;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\VerificationPipeline;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;

class VerificationPipelineTest extends TestCase
{
    protected string $testPackagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testPackagePath = base_path('packages/acme/test-pkg');
        File::ensureDirectoryExists($this->testPackagePath);
        File::put($this->testPackagePath.'/composer.json', json_encode([
            'name' => 'acme/test-pkg',
            'type' => 'library',
        ]));
    }

    public function test_container_resolves_pipeline_with_default_checks(): void
    {
        /** @var VerificationPipeline $pipeline */
        $pipeline = $this->app->make(VerificationPipeline::class);

        $checks = $pipeline->getChecks();

        $this->assertArrayHasKey('composer', $checks);
        $this->assertArrayHasKey('pint', $checks);
        $this->assertArrayHasKey('phpstan', $checks);
        $this->assertArrayHasKey('tests', $checks);
        $this->assertArrayHasKey('isolated', $checks);
        $this->assertArrayHasKey('git_cleanliness', $checks);
        $this->assertArrayHasKey('readme', $checks);
        $this->assertArrayHasKey('export_ignore', $checks);
    }

    public function test_it_resolves_check_by_name_and_alias(): void
    {
        /** @var VerificationPipeline $pipeline */
        $pipeline = $this->app->make(VerificationPipeline::class);

        $checkDirect = $pipeline->getCheck('composer');
        $checkAlias = $pipeline->getCheck('composer_validate');

        $this->assertNotNull($checkDirect);
        $this->assertSame($checkDirect, $checkAlias);
        $this->assertSame('composer', $checkDirect->name());
    }

    public function test_it_can_register_custom_check_dynamically(): void
    {
        $pipeline = new VerificationPipeline;

        $customCheck = new class implements PackageCheckInterface
        {
            public function name(): string
            {
                return 'custom_lint';
            }

            public function title(): string
            {
                return 'CUSTOM LINT';
            }

            public function tiers(): array
            {
                return ['quick', 'deep'];
            }

            public function isApplicable(string $packagePath, ?string $packageName = null, array $options = []): bool
            {
                return true;
            }

            public function execute(string $packagePath, ?string $packageName = null, array $options = []): CheckResult
            {
                return new CheckResult(
                    check: 'custom_lint',
                    package: 'acme/test-pkg',
                    status: 'passed',
                    output: 'All clear'
                );
            }
        };

        $pipeline->registerCheck($customCheck, ['custom_alias']);

        $this->assertSame($customCheck, $pipeline->getCheck('custom_lint'));
        $this->assertSame($customCheck, $pipeline->getCheck('custom_alias'));

        $results = $pipeline->run($this->testPackagePath, 'acme/test-pkg', tier: 'quick');
        $this->assertArrayHasKey('custom_lint', $results);
        $this->assertTrue($results['custom_lint']->isPassed());
    }

    public function test_it_filters_checks_by_tier(): void
    {
        /** @var VerificationPipeline $pipeline */
        $pipeline = $this->app->make(VerificationPipeline::class);

        $quickChecks = $pipeline->getChecksForTier('quick');
        $this->assertArrayHasKey('composer', $quickChecks);
        $this->assertArrayHasKey('pint', $quickChecks);
        $this->assertArrayNotHasKey('phpstan', $quickChecks);
        $this->assertArrayNotHasKey('tests', $quickChecks);
        $this->assertArrayNotHasKey('readme', $quickChecks);

        $deepChecks = $pipeline->getChecksForTier('deep');
        $this->assertArrayHasKey('composer', $deepChecks);
        $this->assertArrayHasKey('pint', $deepChecks);
        $this->assertArrayHasKey('phpstan', $deepChecks);
        $this->assertArrayHasKey('tests', $deepChecks);
        $this->assertArrayNotHasKey('readme', $deepChecks);

        $auditChecks = $pipeline->getChecksForTier('audit');
        $this->assertArrayHasKey('composer', $auditChecks);
        $this->assertArrayHasKey('pint', $auditChecks);
        $this->assertArrayHasKey('phpstan', $auditChecks);
        $this->assertArrayHasKey('tests', $auditChecks);
        $this->assertArrayHasKey('git_cleanliness', $auditChecks);
        $this->assertArrayHasKey('readme', $auditChecks);
        $this->assertArrayHasKey('export_ignore', $auditChecks);
    }

    public function test_it_filters_by_only_with_check_names_and_aliases(): void
    {
        $pipeline = new VerificationPipeline;

        $check1 = new class implements PackageCheckInterface
        {
            public function name(): string
            {
                return 'first';
            }

            public function title(): string
            {
                return 'FIRST';
            }

            public function tiers(): array
            {
                return ['quick'];
            }

            public function isApplicable(string $packagePath, ?string $packageName = null, array $options = []): bool
            {
                return true;
            }

            public function execute(string $packagePath, ?string $packageName = null, array $options = []): CheckResult
            {
                return new CheckResult(
                    check: 'first',
                    package: 'acme/test-pkg',
                    status: 'passed',
                    output: 'OK'
                );
            }
        };

        $check2 = new class implements PackageCheckInterface
        {
            public function name(): string
            {
                return 'second';
            }

            public function title(): string
            {
                return 'SECOND';
            }

            public function tiers(): array
            {
                return ['quick'];
            }

            public function isApplicable(string $packagePath, ?string $packageName = null, array $options = []): bool
            {
                return true;
            }

            public function execute(string $packagePath, ?string $packageName = null, array $options = []): CheckResult
            {
                return new CheckResult(
                    check: 'second',
                    package: 'acme/test-pkg',
                    status: 'passed',
                    output: 'OK'
                );
            }
        };

        $pipeline->registerCheck($check1, ['first_alias']);
        $pipeline->registerCheck($check2);

        $results = $pipeline->run($this->testPackagePath, 'acme/test-pkg', only: ['first_alias']);

        $this->assertCount(1, $results);
        $this->assertArrayHasKey('first', $results);
        $this->assertArrayNotHasKey('second', $results);
    }

    public function test_it_skips_non_applicable_checks(): void
    {
        $pipeline = new VerificationPipeline;

        $inapplicableCheck = new class implements PackageCheckInterface
        {
            public function name(): string
            {
                return 'conditional';
            }

            public function title(): string
            {
                return 'CONDITIONAL';
            }

            public function tiers(): array
            {
                return ['quick'];
            }

            public function isApplicable(string $packagePath, ?string $packageName = null, array $options = []): bool
            {
                return false;
            }

            public function execute(string $packagePath, ?string $packageName = null, array $options = []): CheckResult
            {
                return new CheckResult(
                    check: 'conditional',
                    package: 'acme/test-pkg',
                    status: 'failed',
                    output: 'Should not run'
                );
            }
        };

        $pipeline->registerCheck($inapplicableCheck);

        $results = $pipeline->run($this->testPackagePath, 'acme/test-pkg');
        $this->assertArrayNotHasKey('conditional', $results);
    }

    public function test_it_captures_exceptions_as_failures(): void
    {
        $pipeline = new VerificationPipeline;

        $brokenCheck = new class implements PackageCheckInterface
        {
            public function name(): string
            {
                return 'broken';
            }

            public function title(): string
            {
                return 'BROKEN';
            }

            public function tiers(): array
            {
                return ['quick'];
            }

            public function isApplicable(string $packagePath, ?string $packageName = null, array $options = []): bool
            {
                return true;
            }

            public function execute(string $packagePath, ?string $packageName = null, array $options = []): CheckResult
            {
                throw new \RuntimeException('Unexpected failure during check execution');
            }
        };

        $pipeline->registerCheck($brokenCheck);

        $results = $pipeline->run($this->testPackagePath, 'acme/test-pkg', tier: 'quick');

        $this->assertArrayHasKey('broken', $results);
        $this->assertTrue($results['broken']->isFailed());
        $this->assertStringContainsString('Check failed with exception', $results['broken']->output);
    }
}
