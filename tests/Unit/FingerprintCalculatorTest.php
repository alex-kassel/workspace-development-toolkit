<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Unit;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\CheckResult;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\FingerprintCalculator;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;

class FingerprintCalculatorTest extends TestCase
{
    protected FingerprintCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new FingerprintCalculator;
    }

    public function test_fingerprint_is_deterministic_and_reproducible(): void
    {
        $checks = [
            'composer' => new CheckResult('composer', 'acme/pkg', 'passed', 'OK', 0.1),
            'pint' => new CheckResult('pint', 'acme/pkg', 'passed', 'OK', 0.2),
        ];

        $fp1 = $this->calculator->compute('abc123tree', $checks);
        $fp2 = $this->calculator->compute('abc123tree', $checks);

        $this->assertSame($fp1, $fp2);
        $this->assertStringStartsWith('sha256:', $fp1);
    }

    public function test_different_tree_hash_produces_different_fingerprint(): void
    {
        $checks = [
            'composer' => new CheckResult('composer', 'acme/pkg', 'passed', 'OK', 0.1),
        ];

        $fp1 = $this->calculator->compute('tree1', $checks);
        $fp2 = $this->calculator->compute('tree2', $checks);

        $this->assertNotSame($fp1, $fp2);
    }

    public function test_different_check_status_produces_different_fingerprint(): void
    {
        $checksPass = [
            'composer' => new CheckResult('composer', 'acme/pkg', 'passed', 'OK', 0.1),
        ];

        $checksFail = [
            'composer' => new CheckResult('composer', 'acme/pkg', 'failed', 'OK', 0.1),
        ];

        $fp1 = $this->calculator->compute('tree1', $checksPass);
        $fp2 = $this->calculator->compute('tree1', $checksFail);

        $this->assertNotSame($fp1, $fp2);
    }

    public function test_output_normalization_strips_dynamic_timing_and_temp_paths(): void
    {
        $output1 = '{"duration_ms": 125, "tests": 5} in /tmp/package-audit-12345/vendor';
        $output2 = '{"duration_ms": 980, "tests": 5} in /tmp/package-audit-67890/vendor';

        $checks1 = [
            'tests' => new CheckResult('tests', 'acme/pkg', 'passed', $output1, 0.1),
        ];
        $checks2 = [
            'tests' => new CheckResult('tests', 'acme/pkg', 'passed', $output2, 0.9),
        ];

        $fp1 = $this->calculator->compute('tree1', $checks1);
        $fp2 = $this->calculator->compute('tree1', $checks2);

        $this->assertSame($fp1, $fp2);
    }
}
