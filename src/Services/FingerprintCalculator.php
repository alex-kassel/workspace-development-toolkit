<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use AlexKassel\StableFingerprint\StableFingerprint;
use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\CheckResult;

class FingerprintCalculator
{
    /**
     * Compute a deterministic SHA-256 fingerprint from the git tree hash and checks using StableFingerprint.
     *
     * @param  array<string, CheckResult|array<string, mixed>>  $checks
     */
    public function compute(string $treeHash, array $checks): string
    {
        $canonicalChecks = [];

        foreach ($checks as $key => $r) {
            if ($r instanceof CheckResult) {
                $canonicalChecks[$key] = [
                    'check' => $r->check,
                    'status' => $r->status,
                    'output_hash' => hash('sha256', $this->normalizeOutput($r->output)),
                ];
            } elseif (is_array($r)) {
                $outputHash = isset($r['output_hash'])
                    ? (string) $r['output_hash']
                    : hash('sha256', $this->normalizeOutput((string) ($r['output'] ?? '')));

                $canonicalChecks[$key] = [
                    'check' => (string) ($r['check'] ?? $key),
                    'status' => (string) ($r['status'] ?? 'unknown'),
                    'output_hash' => $outputHash,
                ];
            }
        }

        $payload = [
            'tree_hash' => $treeHash,
            'checks' => $canonicalChecks,
        ];

        return 'sha256:'.StableFingerprint::compute($payload);
    }

    /**
     * Normalize dynamic output (e.g. execution times, memory usage, temp directories)
     * so that the output hash is strictly deterministic across independent verification runs.
     */
    public function normalizeOutput(string $output): string
    {
        // Normalize line breaks
        $normalized = str_replace(["\r\n", "\r"], "\n", $output);

        // Normalize dynamic test runner elapsed durations and memory stats
        $normalized = (string) preg_replace('/"duration_ms":\s*\d+/', '"duration_ms":0', $normalized);
        $normalized = (string) preg_replace('/Time:\s*[0-9:.]+(?:,\s*Memory:\s*[0-9.]+\s*[KMGT]?B)?/i', 'Time: 00:00.000', $normalized);

        // Normalize ephemeral temp directory paths (Windows & Unix)
        $normalized = (string) preg_replace('~[A-Za-z]:[/\\\\][^\n"\'\s]*(?:package-audit|wdt)[^\n"\'\s]*~i', '<TEMP_DIR>', $normalized);
        $normalized = (string) preg_replace('~/(?:tmp|private/var/folders)/[^\n"\'\s]*(?:package-audit|wdt)[^\n"\'\s]*~i', '<TEMP_DIR>', $normalized);

        // Normalize git branch in cleanliness output (since verification may run on detached HEAD)
        $normalized = (string) preg_replace('/,\s*Branch:\s*[^\n\r]+/i', ', Branch: <BRANCH>', $normalized);

        return trim($normalized);
    }
}
