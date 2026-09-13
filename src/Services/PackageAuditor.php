<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\AuditReport;
use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\CheckResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class PackageAuditor
{
    public const AUDITOR_VERSION = '1.0.0';

    public function __construct(
        protected PackageResolver $packageResolver,
        protected GitInspector $gitInspector,
        protected PackageVerifier $packageVerifier,
        protected ReadmeValidator $readmeValidator,
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

        $checks = [];

        // 1. Git Cleanliness
        $checks['git_cleanliness'] = $this->checkGitCleanliness($fullPath, $canonicalName);

        // 2. Composer Validate
        $checks['composer_validate'] = $this->packageVerifier->checkComposer($fullPath, $canonicalName);

        // 3. Pint (read-only style test)
        $checks['pint'] = $this->packageVerifier->checkPint($fullPath, $canonicalName, fix: false);

        // 4. PHPStan
        $checks['phpstan'] = $this->packageVerifier->checkPhpstan($fullPath, $canonicalName);

        // 5. Automated Tests
        $checks['tests'] = $this->packageVerifier->checkTests($fullPath, $canonicalName);

        // 6. Isolated Standalone Installation
        $checks['isolated'] = $this->packageVerifier->checkIsolated($fullPath, $canonicalName);

        // 7. README Standard Compliance
        $checks['readme'] = $this->checkReadme($fullPath, $canonicalName);

        // 8. Export-ignore in .gitattributes
        $checks['export_ignore'] = $this->checkExportIgnore($fullPath, $canonicalName);

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
     * Check Git cleanliness and independent repository.
     */
    protected function checkGitCleanliness(string $packagePath, string $packageName): CheckResult
    {
        $startTime = microtime(true);

        if (! $this->gitInspector->hasGitRepository($packagePath)) {
            return new CheckResult(
                check: 'git_cleanliness',
                package: $packageName,
                status: 'failed',
                output: 'Not an independent git repository (.git directory missing in package root).',
                durationSeconds: (float) round(microtime(true) - $startTime, 3),
            );
        }

        $statusProcess = Process::path($packagePath)->run(['git', 'status', '--porcelain']);
        if (! $statusProcess->successful()) {
            return new CheckResult(
                check: 'git_cleanliness',
                package: $packageName,
                status: 'failed',
                output: 'Failed to query git status: '.$statusProcess->errorOutput(),
                durationSeconds: (float) round(microtime(true) - $startTime, 3),
            );
        }

        $statusOutput = trim($statusProcess->output());
        if ($statusOutput !== '') {
            $lines = array_filter(explode("\n", str_replace("\r", '', $statusOutput)));
            $unrelated = array_filter($lines, function ($line) {
                $file = trim(substr($line, 3));

                return $file !== 'AUDIT.json';
            });

            if (! empty($unrelated)) {
                return new CheckResult(
                    check: 'git_cleanliness',
                    package: $packageName,
                    status: 'failed',
                    output: "Working tree is dirty. Uncommitted changes detected:\n".implode("\n", $unrelated),
                    durationSeconds: (float) round(microtime(true) - $startTime, 3),
                );
            }
        }

        return new CheckResult(
            check: 'git_cleanliness',
            package: $packageName,
            status: 'passed',
            output: 'Git repository is clean. No uncommitted changes.',
            durationSeconds: (float) round(microtime(true) - $startTime, 3),
        );
    }

    /**
     * Check README compliance.
     */
    protected function checkReadme(string $packagePath, string $packageName): CheckResult
    {
        $startTime = microtime(true);
        $readmeResult = $this->readmeValidator->validate($packagePath);

        $status = $readmeResult['status'] === 'passed' ? 'passed' : 'failed';
        $failedChecks = array_filter($readmeResult['checks'], fn ($c) => $c['status'] === 'failed');
        $output = $status === 'passed'
            ? 'README matches configured standard sections.'
            : "README standard violations found:\n".implode("\n", array_map(fn ($c) => $c['message'], $failedChecks));

        return new CheckResult(
            check: 'readme',
            package: $packageName,
            status: $status,
            output: $output,
            durationSeconds: (float) round(microtime(true) - $startTime, 3),
        );
    }

    /**
     * Check .gitattributes for export-ignore directives.
     */
    protected function checkExportIgnore(string $packagePath, string $packageName): CheckResult
    {
        $startTime = microtime(true);
        $gitattrPath = $packagePath.DIRECTORY_SEPARATOR.'.gitattributes';

        if (! File::exists($gitattrPath)) {
            return new CheckResult(
                check: 'export_ignore',
                package: $packageName,
                status: 'failed',
                output: 'Missing .gitattributes file in package root.',
                durationSeconds: (float) round(microtime(true) - $startTime, 3),
            );
        }

        $content = File::get($gitattrPath);
        if (! str_contains($content, 'export-ignore')) {
            return new CheckResult(
                check: 'export_ignore',
                package: $packageName,
                status: 'failed',
                output: '.gitattributes exists but contains no export-ignore directives.',
                durationSeconds: (float) round(microtime(true) - $startTime, 3),
            );
        }

        return new CheckResult(
            check: 'export_ignore',
            package: $packageName,
            status: 'passed',
            output: '.gitattributes contains valid export-ignore directives.',
            durationSeconds: (float) round(microtime(true) - $startTime, 3),
        );
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
