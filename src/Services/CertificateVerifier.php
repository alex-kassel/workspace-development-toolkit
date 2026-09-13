<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\AuditReport;
use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\VerificationResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class CertificateVerifier
{
    public function __construct(
        protected PackageResolver $packageResolver,
        protected GitInspector $gitInspector,
        protected FingerprintCalculator $fingerprintCalculator,
    ) {}

    /**
     * Verify an existing audit certificate (AUDIT.json) against the package source and git history.
     */
    public function verify(string $packageNameOrPath): VerificationResult
    {
        $packagePath = $this->packageResolver->findPackagePath($packageNameOrPath);
        if ($packagePath === null) {
            $normalized = trim(str_replace('\\', '/', $packageNameOrPath), '/');
            if (File::isDirectory(base_path($normalized))) {
                $packagePath = $normalized;
            } elseif (File::isDirectory($packageNameOrPath)) {
                $packagePath = $packageNameOrPath;
            } else {
                throw new RuntimeException("Package [{$packageNameOrPath}] not found in any registered workspace.");
            }
        }

        $fullPath = base_path($packagePath);
        $certificatePath = $fullPath.DIRECTORY_SEPARATOR.'AUDIT.json';

        // 1. Check if certificate file exists
        if (! File::exists($certificatePath)) {
            return new VerificationResult(
                verified: false,
                status: 'MISSING',
                reason: 'Audit certificate file [AUDIT.json] not found in package root.',
            );
        }

        // 2. Read and parse certificate
        try {
            $certificateJson = (string) File::get($certificatePath);
            $report = AuditReport::fromJson($certificateJson);
        } catch (Throwable $e) {
            return new VerificationResult(
                verified: false,
                status: 'FORGED',
                reason: 'Malformed or unparseable certificate JSON: '.$e->getMessage(),
            );
        }

        $certifiedCommit = $report->commit;
        $certifiedTreeHash = $report->treeHash;

        if ($certifiedCommit === '' || $certifiedTreeHash === '') {
            return new VerificationResult(
                verified: false,
                status: 'FORGED',
                reason: 'Certificate is missing certified commit or tree hash.',
                certificate: $report,
            );
        }

        // 3. Verify git repository exists
        if (! $this->gitInspector->hasGitRepository($fullPath)) {
            return new VerificationResult(
                verified: false,
                status: 'FORGED',
                reason: 'Package directory is not a valid git repository.',
                certificate: $report,
            );
        }

        // 4. Verify tree_hash: git rev-parse {commit}^{tree} == certificate.tree_hash
        $treeProcess = Process::path($fullPath)->run(['git', 'rev-parse', "{$certifiedCommit}^{tree}"]);
        if (! $treeProcess->successful()) {
            return new VerificationResult(
                verified: false,
                status: 'FORGED',
                reason: "Certified commit [{$certifiedCommit}] is not reachable in git repository.",
                certificate: $report,
            );
        }

        $actualTreeHash = trim($treeProcess->output());
        if ($actualTreeHash !== $certifiedTreeHash) {
            return new VerificationResult(
                verified: false,
                status: 'FORGED',
                reason: "Tree hash mismatch: certificate specifies [{$certifiedTreeHash}], but commit tree is [{$actualTreeHash}].",
                certificate: $report,
            );
        }

        // 5. Check if source code has drifted since the certified commit
        $driftProcess = Process::path($fullPath)->run([
            'git', 'rev-list', '--count', "{$certifiedCommit}..HEAD", '--', 'src/', 'config/', 'composer.json',
        ]);

        if ($driftProcess->successful()) {
            $driftCount = (int) trim($driftProcess->output());
            if ($driftCount > 0) {
                return new VerificationResult(
                    verified: false,
                    status: 'OUTDATED',
                    reason: "Source code drift detected: {$driftCount} source commit(s) since certificate was issued at {$certifiedCommit}.",
                    certificate: $report,
                );
            }
        }

        // 6. Verify fingerprint matches canonical calculation
        $computedFingerprint = $this->fingerprintCalculator->compute($certifiedTreeHash, $report->checks);
        if ($computedFingerprint !== $report->fingerprint) {
            return new VerificationResult(
                verified: false,
                status: 'FORGED',
                reason: "Fingerprint mismatch: certificate specifies [{$report->fingerprint}], but computed value is [{$computedFingerprint}].",
                certificate: $report,
            );
        }

        // 7. Verify overall verdict was PASSED
        if ($report->verdict !== 'PASSED') {
            return new VerificationResult(
                verified: false,
                status: 'FAILED',
                reason: "Certificate explicitly indicates a non-passing verdict: [{$report->verdict}].",
                certificate: $report,
            );
        }

        return new VerificationResult(
            verified: true,
            status: 'VERIFIED',
            reason: null,
            certificate: $report,
        );
    }
}
