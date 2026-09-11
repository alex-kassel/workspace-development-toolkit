<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class ReleaseChecker
{
    public function __construct(
        protected PackageResolver $packageResolver,
        protected GitInspector $gitInspector,
        protected PackageVerifier $packageVerifier,
        protected ReadmeValidator $readmeValidator,
    ) {}

    /**
     * Run pre-flight release gate checks.
     *
     * @return array{
     *     package: string,
     *     path: string,
     *     verdict: string,
     *     latest_tag: string,
     *     checks: array<string, array{name: string, status: string, message: string}>
     * }
     */
    public function check(string $packageNameOrPath, bool $fast = false): array
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
        if (! File::isDirectory($fullPath)) {
            $fullPath = $packagePath;
            if (! File::isDirectory($fullPath)) {
                throw new RuntimeException("Package directory does not exist: {$fullPath}");
            }
        }

        $packageName = $this->packageResolver->resolveCanonicalPackageName($packageNameOrPath);
        if ($packageName === $packageNameOrPath) {
            $composerJsonPath = $fullPath.DIRECTORY_SEPARATOR.'composer.json';
            if (File::exists($composerJsonPath)) {
                $manifest = json_decode(File::get($composerJsonPath), true) ?: [];
                if (! empty($manifest['name'])) {
                    $packageName = (string) $manifest['name'];
                }
            }
        }

        $checks = [];

        // 1. Independent Git Repository & Clean working tree
        if (! $this->gitInspector->hasGitRepository($fullPath)) {
            $checks['git_repo'] = [
                'name' => 'Independent Git Repository',
                'status' => 'failed',
                'message' => 'No independent .git directory found inside package root.',
            ];
            $checks['git_tree'] = [
                'name' => 'Clean Git Working Tree',
                'status' => 'skipped',
                'message' => 'Skipped because git is not initialized.',
            ];
            $latestTag = 'none (not a git repo)';
        } else {
            $checks['git_repo'] = [
                'name' => 'Independent Git Repository',
                'status' => 'passed',
                'message' => 'Independent git repository verified.',
            ];

            if ($this->gitInspector->isClean($fullPath)) {
                $checks['git_tree'] = [
                    'name' => 'Clean Git Working Tree',
                    'status' => 'passed',
                    'message' => 'Working tree is clean. No uncommitted changes.',
                ];
            } else {
                $checks['git_tree'] = [
                    'name' => 'Clean Git Working Tree',
                    'status' => 'failed',
                    'message' => 'Uncommitted or untracked changes detected in working tree.',
                ];
            }

            $latestTag = $this->gitInspector->getLatestTag($fullPath) ?? 'none (initial release)';
        }

        // 2. Audit Certificate Freshness Gate (RELEASE-GATE.md)
        $releaseGateFile = $fullPath.DIRECTORY_SEPARATOR.'RELEASE-GATE.md';
        if (File::exists($releaseGateFile)) {
            $certifiedCommit = $this->extractCertifiedCommit(File::get($releaseGateFile));
            if ($certifiedCommit !== null && $this->gitInspector->hasGitRepository($fullPath)) {
                $delta = Process::path($fullPath)->run(['git', 'rev-list', '--count', "{$certifiedCommit}..HEAD", '--', 'src/', 'config/', 'composer.json']);
                if ($delta->successful()) {
                    $count = (int) trim($delta->output());
                    if ($count === 0) {
                        $checks['audit_freshness'] = [
                            'name' => 'Audit Freshness Gate',
                            'status' => 'passed',
                            'message' => "Audit certificate is up to date (0 source commits since {$certifiedCommit}).",
                        ];
                    } else {
                        $checks['audit_freshness'] = [
                            'name' => 'Audit Freshness Gate',
                            'status' => 'action_required',
                            'message' => "Source code drift detected: {$count} commit(s) since certificate at {$certifiedCommit}.",
                        ];
                    }
                } else {
                    $checks['audit_freshness'] = [
                        'name' => 'Audit Freshness Gate',
                        'status' => 'action_required',
                        'message' => "Certified commit {$certifiedCommit} not reachable in git history.",
                    ];
                }
            } elseif ($certifiedCommit === null) {
                $checks['audit_freshness'] = [
                    'name' => 'Audit Freshness Gate',
                    'status' => 'action_required',
                    'message' => 'RELEASE-GATE.md present but missing a certified commit hash.',
                ];
            } else {
                $checks['audit_freshness'] = [
                    'name' => 'Audit Freshness Gate',
                    'status' => 'passed',
                    'message' => "RELEASE-GATE.md present with certified commit {$certifiedCommit}.",
                ];
            }
        } else {
            $checks['audit_freshness'] = [
                'name' => 'Audit Freshness Gate',
                'status' => 'not_configured',
                'message' => 'No RELEASE-GATE.md found (unaudited package).',
            ];
        }

        // 3. Export-Ignore in .gitattributes
        $gitattrPath = $fullPath.DIRECTORY_SEPARATOR.'.gitattributes';
        if (File::exists($gitattrPath)) {
            $attrContent = File::get($gitattrPath);
            if (str_contains($attrContent, 'export-ignore')) {
                $checks['export_ignore'] = [
                    'name' => 'Distribution Archive (.gitattributes)',
                    'status' => 'passed',
                    'message' => 'export-ignore directives configured for release.',
                ];
            } else {
                $checks['export_ignore'] = [
                    'name' => 'Distribution Archive (.gitattributes)',
                    'status' => 'failed',
                    'message' => '.gitattributes exists but missing export-ignore directives.',
                ];
            }
        } else {
            $checks['export_ignore'] = [
                'name' => 'Distribution Archive (.gitattributes)',
                'status' => 'failed',
                'message' => 'Missing .gitattributes file in package.',
            ];
        }

        // 4. Code Quality Suite
        $runIsolated = ! $fast;
        $verifyResults = $this->packageVerifier->checkAll($fullPath, $packageName, isolated: $runIsolated);
        $qualityPassed = true;
        foreach ($verifyResults as $result) {
            if ($result->status === 'failed') {
                $qualityPassed = false;
                break;
            }
        }

        $checks['code_quality'] = [
            'name' => $runIsolated
                ? 'Code Quality Suite (Pint, PHPStan, Tests, Composer, Isolated)'
                : 'Code Quality Suite (Pint, PHPStan, Tests, Composer [fast])',
            'status' => $qualityPassed ? 'passed' : 'failed',
            'message' => $qualityPassed
                ? ($runIsolated ? 'All quality, tests, and standalone installation checks passed.' : 'All fast quality checks passed.')
                : 'Required code quality checks failed.',
        ];

        // 5. README Standard Compliance
        $readmeResult = $this->readmeValidator->validate($fullPath);
        $checks['readme_compliance'] = [
            'name' => 'README Standard Compliance',
            'status' => $readmeResult['status'],
            'message' => ($readmeResult['status'] === 'passed')
                ? 'README matches configured standard.'
                : "README has {$readmeResult['summary']['failed']} violation(s). Run package:readme for details.",
        ];

        // Calculate final verdict
        $hasHardFailures = false;
        $hasActionRequired = false;
        foreach ($checks as $check) {
            if ($check['status'] === 'failed') {
                $hasHardFailures = true;
            } elseif ($check['status'] === 'action_required') {
                $hasActionRequired = true;
            }
        }

        $verdict = 'READY';
        if ($hasHardFailures) {
            $verdict = 'BLOCKED';
        } elseif ($hasActionRequired) {
            $verdict = 'ACTION_REQUIRED';
        }

        return [
            'package' => $packageName,
            'path' => $packagePath,
            'verdict' => $verdict,
            'latest_tag' => $latestTag,
            'checks' => $checks,
        ];
    }

    protected function extractCertifiedCommit(string $content): ?string
    {
        $lines = preg_split('/\r?\n/', $content) ?: [];
        foreach ($lines as $line) {
            if (preg_match('/commit/i', $line)) {
                if (preg_match('/\b([a-f0-9]{7,40})\b/i', $line, $matches)) {
                    return strtolower($matches[1]);
                }
            }
        }

        return null;
    }
}
