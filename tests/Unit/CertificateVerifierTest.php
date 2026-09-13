<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Unit;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\AuditReport;
use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\CheckResult;
use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\CertificateVerifier;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\FingerprintCalculator;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class CertificateVerifierTest extends TestCase
{
    protected CertificateVerifier $verifier;

    protected FingerprintCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->verifier = app(CertificateVerifier::class);
        $this->calculator = app(FingerprintCalculator::class);
    }

    public function test_verify_returns_missing_when_no_audit_json(): void
    {
        Workspace::add('packages', null, true);
        $dir = $this->tempDir.'/packages/acme/my-pkg';
        File::ensureDirectoryExists($dir.'/.git');
        File::put($dir.'/composer.json', json_encode(['name' => 'acme/my-pkg']));

        $result = $this->verifier->verify('acme/my-pkg');

        $this->assertFalse($result->verified);
        $this->assertSame('MISSING', $result->status);
    }

    public function test_verify_returns_forged_when_tree_hash_mismatches(): void
    {
        Workspace::add('packages', null, true);
        $dir = $this->tempDir.'/packages/acme/my-pkg';
        File::ensureDirectoryExists($dir.'/.git');
        File::put($dir.'/composer.json', json_encode(['name' => 'acme/my-pkg']));

        $report = new AuditReport(
            package: 'acme/my-pkg',
            version: '1.0.0',
            commit: 'commit123',
            treeHash: 'certifiedTree',
            branch: 'main',
            timestamp: now()->toIso8601String(),
            environment: [],
            checks: [],
            verdict: 'PASSED',
            fingerprint: 'sha256:fp',
            auditorVersion: '1.0.0',
        );
        File::put($dir.'/AUDIT.json', $report->toJson());

        Process::fake(function ($process) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
            if (str_contains($cmd, 'rev-parse') && str_contains($cmd, '^{tree}')) {
                return Process::result('differentTree');
            }

            return Process::result('');
        });

        $result = $this->verifier->verify('acme/my-pkg');

        $this->assertFalse($result->verified);
        $this->assertSame('FORGED', $result->status);
        $this->assertStringContainsString('Tree hash mismatch', (string) $result->reason);
    }

    public function test_verify_returns_verified_when_all_match(): void
    {
        Workspace::add('packages', null, true);
        $dir = $this->tempDir.'/packages/acme/my-pkg';
        File::ensureDirectoryExists($dir.'/.git');
        File::put($dir.'/composer.json', json_encode(['name' => 'acme/my-pkg']));

        $checks = [
            'composer' => new CheckResult('composer', 'acme/my-pkg', 'passed', 'OK'),
        ];
        $treeHash = 'realTree';
        $fingerprint = $this->calculator->compute($treeHash, $checks);

        $report = new AuditReport(
            package: 'acme/my-pkg',
            version: '1.0.0',
            commit: 'commit123',
            treeHash: $treeHash,
            branch: 'main',
            timestamp: now()->toIso8601String(),
            environment: [],
            checks: $checks,
            verdict: 'PASSED',
            fingerprint: $fingerprint,
            auditorVersion: '1.0.0',
        );
        File::put($dir.'/AUDIT.json', $report->toJson());

        Process::fake(function ($process) use ($treeHash) {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
            if (str_contains($cmd, 'rev-parse') && str_contains($cmd, '^{tree}')) {
                return Process::result($treeHash);
            }
            if (str_contains($cmd, 'rev-list')) {
                return Process::result("0\n");
            }

            return Process::result('OK');
        });

        $result = $this->verifier->verify('acme/my-pkg');

        $this->assertTrue($result->verified);
        $this->assertSame('VERIFIED', $result->status);
    }
}
