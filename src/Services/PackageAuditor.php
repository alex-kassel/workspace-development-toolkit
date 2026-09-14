<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\AuditReport;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class PackageAuditor
{
    public const AUDITOR_VERSION = '1.0.0';

    public function __construct(
        protected PackageResolver $packageResolver,
        protected GitInspector $gitInspector,
        protected VerificationPipeline $pipeline,
        protected FingerprintCalculator $fingerprintCalculator,
    ) {}

    /**
     * Run full package audit, evaluate against quality gates, compute cryptographic fingerprint,
     * and optionally issue AUDIT.json with git commit and tag.
     */
    public function audit(
        string $packageNameOrPath,
        ?string $targetVersion = null,
        bool $noCommit = false,
        bool $noTag = false
    ): AuditReport {
        if (File::isDirectory($packageNameOrPath)) {
            $fullPath = realpath($packageNameOrPath) ?: $packageNameOrPath;
            $canonicalName = $this->resolveNameFromComposer($fullPath);
        } else {
            $packagePath = $this->packageResolver->findPackagePath($packageNameOrPath);
            if ($packagePath === null) {
                $normalized = trim(str_replace('\\', '/', $packageNameOrPath), '/');
                if (File::isDirectory(base_path($normalized))) {
                    $fullPath = base_path($normalized);
                    $canonicalName = $this->resolveNameFromComposer($fullPath);
                } else {
                    throw new RuntimeException("Package [{$packageNameOrPath}] not found in any registered workspace.");
                }
            } else {
                $fullPath = base_path($packagePath);
                $canonicalName = $this->packageResolver->resolveCanonicalPackageName($packageNameOrPath);
            }
        }

        $version = $targetVersion ?? $this->resolvePackageVersion($fullPath);

        // Execute all audit checks via the Verification Pipeline
        $pipelineResults = $this->pipeline->run($fullPath, $canonicalName, tier: 'audit', options: ['fix' => false]);
        $checks = [];
        foreach ($pipelineResults as $name => $result) {
            $key = $name === 'composer' ? 'composer_validate' : $name;
            $checks[$key] = $result;
        }

        // Git metadata
        $commit = $this->gitInspector->getCommitHash($fullPath);
        $treeHash = $this->gitInspector->getTreeHash($fullPath);
        $branch = $this->gitInspector->getCurrentBranch($fullPath) ?? 'main';

        // Overall verdict calculation
        $allPassed = true;
        foreach ($checks as $check) {
            if ($check->isFailed()) {
                $allPassed = false;
                break;
            }
        }

        $verdict = $allPassed ? 'PASSED' : 'FAILED';
        $fingerprint = $this->fingerprintCalculator->compute($treeHash, $checks);

        $environment = [
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'os' => strtolower(PHP_OS_FAMILY),
        ];

        $report = new AuditReport(
            package: $canonicalName,
            version: $version,
            commit: $commit,
            treeHash: $treeHash,
            branch: $branch,
            timestamp: now()->toIso8601String(),
            environment: $environment,
            checks: $checks,
            verdict: $verdict,
            fingerprint: $fingerprint,
            auditorVersion: self::AUDITOR_VERSION,
        );

        // Issue and commit certificate if audit passed and not in dry-run mode
        if ($allPassed && ! $noCommit && $this->gitInspector->hasGitRepository($fullPath)) {
            $this->issueCertificate($fullPath, $report, $version, $noTag);
        }

        return $report;
    }

    /**
     * Write AUDIT.json, tag, and commit.
     */
    protected function issueCertificate(string $packagePath, AuditReport $report, string $version, bool $noTag): void
    {
        $certPath = $packagePath.DIRECTORY_SEPARATOR.'AUDIT.json';

        // 1. Tag commit PRIOR to adding AUDIT.json so the tag points to the audited code
        if (! $noTag) {
            $tag = "audit/v{$version}";
            Process::path($packagePath)->run(['git', 'tag', '-f', $tag]);
        }

        // 2. Write AUDIT.json
        File::put($certPath, $report->toJson()."\n");

        // 3. Stage and commit AUDIT.json
        Process::path($packagePath)->run(['git', 'add', 'AUDIT.json']);
        Process::path($packagePath)->run(['git', 'commit', '-m', "Audit certificate for v{$version}"]);
    }

    /**
     * Resolve package version from latest tag, composer.json, or default to 0.1.0.
     */
    protected function resolvePackageVersion(string $packagePath): string
    {
        $tag = $this->gitInspector->getLatestTag($packagePath);
        if ($tag !== null && $tag !== '') {
            return ltrim($tag, 'v');
        }

        $composerPath = $packagePath.DIRECTORY_SEPARATOR.'composer.json';
        if (File::exists($composerPath)) {
            $json = json_decode(File::get($composerPath), true);
            if (is_array($json) && ! empty($json['version'])) {
                return ltrim((string) $json['version'], 'v');
            }
        }

        return '0.1.0';
    }

    /**
     * Resolve package name from composer.json.
     */
    protected function resolveNameFromComposer(string $path): string
    {
        $composerPath = $path.DIRECTORY_SEPARATOR.'composer.json';
        if (File::exists($composerPath)) {
            $json = json_decode(File::get($composerPath), true);
            if (is_array($json) && ! empty($json['name'])) {
                return (string) $json['name'];
            }
        }

        return basename($path);
    }
}
