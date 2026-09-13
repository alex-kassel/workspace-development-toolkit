<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Unit;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\AuditReport;
use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\CheckResult;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;

class AuditReportTest extends TestCase
{
    public function test_audit_report_serialization_and_deserialization(): void
    {
        $checks = [
            'composer' => new CheckResult('composer', 'acme/my-pkg', 'passed', 'OK', 0.1),
            'pint' => new CheckResult('pint', 'acme/my-pkg', 'passed', 'OK', 0.2),
        ];

        $report = new AuditReport(
            package: 'acme/my-pkg',
            version: '1.0.0',
            commit: 'abc123commit',
            treeHash: 'def456tree',
            branch: 'main',
            timestamp: '2026-09-13T00:00:00+00:00',
            environment: ['php' => '8.3.0', 'laravel' => '13.0.0', 'os' => 'darwin'],
            checks: $checks,
            verdict: 'PASSED',
            fingerprint: 'sha256:fakefingerprint',
            auditorVersion: '1.0.0',
        );

        $this->assertTrue($report->allPassed());

        $json = $report->toJson();
        $this->assertJson($json);

        $reconstituted = AuditReport::fromJson($json);

        $this->assertSame('acme/my-pkg', $reconstituted->package);
        $this->assertSame('1.0.0', $reconstituted->version);
        $this->assertSame('abc123commit', $reconstituted->commit);
        $this->assertSame('def456tree', $reconstituted->treeHash);
        $this->assertSame('PASSED', $reconstituted->verdict);
        $this->assertSame('sha256:fakefingerprint', $reconstituted->fingerprint);
        $this->assertCount(2, $reconstituted->checks);
        $this->assertTrue($reconstituted->checks['composer']->isPassed());
    }

    public function test_audit_report_all_passed_returns_false_when_check_fails(): void
    {
        $checks = [
            'composer' => new CheckResult('composer', 'acme/my-pkg', 'passed', 'OK', 0.1),
            'phpstan' => new CheckResult('phpstan', 'acme/my-pkg', 'failed', 'Error', 0.5),
        ];

        $report = new AuditReport(
            package: 'acme/my-pkg',
            version: '1.0.0',
            commit: 'abc123commit',
            treeHash: 'def456tree',
            branch: 'main',
            timestamp: '2026-09-13T00:00:00+00:00',
            environment: [],
            checks: $checks,
            verdict: 'FAILED',
            fingerprint: 'sha256:fake',
            auditorVersion: '1.0.0',
        );

        $this->assertFalse($report->allPassed());
    }
}
