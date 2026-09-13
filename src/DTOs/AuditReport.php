<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\DTOs;

final readonly class AuditReport
{
    /**
     * @param  array<string, string>  $environment
     * @param  array<string, CheckResult>  $checks
     */
    public function __construct(
        public string $package,
        public string $version,
        public string $commit,
        public string $treeHash,
        public string $branch,
        public string $timestamp,
        public array $environment,
        public array $checks,
        public string $verdict,
        public string $fingerprint,
        public string $auditorVersion,
    ) {}

    public function allPassed(): bool
    {
        if (empty($this->checks)) {
            return false;
        }

        foreach ($this->checks as $check) {
            if ($check->isFailed()) {
                return false;
            }
        }

        return $this->verdict === 'PASSED';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $serializedChecks = [];
        foreach ($this->checks as $key => $check) {
            $serializedChecks[$key] = $check->toArray();
        }

        return [
            'schema_version' => '1.0.0',
            'auditor_version' => $this->auditorVersion,
            'package' => $this->package,
            'version' => $this->version,
            'audit' => [
                'commit' => $this->commit,
                'tree_hash' => $this->treeHash,
                'branch' => $this->branch,
                'timestamp' => $this->timestamp,
                'environment' => $this->environment,
            ],
            'checks' => $serializedChecks,
            'verdict' => $this->verdict,
            'fingerprint' => $this->fingerprint,
        ];
    }

    public function toJson(): string
    {
        return (string) json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $checks = [];
        $rawChecks = (array) ($data['checks'] ?? []);

        foreach ($rawChecks as $key => $rawCheck) {
            if ($rawCheck instanceof CheckResult) {
                $checks[$key] = $rawCheck;
            } elseif (is_array($rawCheck)) {
                $checks[$key] = CheckResult::fromArray(array_merge(['check' => $key], $rawCheck));
            }
        }

        $audit = (array) ($data['audit'] ?? []);

        return new self(
            package: (string) ($data['package'] ?? ''),
            version: (string) ($data['version'] ?? '0.1.0'),
            commit: (string) ($audit['commit'] ?? $data['commit'] ?? ''),
            treeHash: (string) ($audit['tree_hash'] ?? $data['tree_hash'] ?? ''),
            branch: (string) ($audit['branch'] ?? $data['branch'] ?? 'main'),
            timestamp: (string) ($audit['timestamp'] ?? $data['timestamp'] ?? ''),
            environment: (array) ($audit['environment'] ?? $data['environment'] ?? []),
            checks: $checks,
            verdict: (string) ($data['verdict'] ?? 'FAILED'),
            fingerprint: (string) ($data['fingerprint'] ?? ''),
            auditorVersion: (string) ($data['auditor_version'] ?? '1.0.0'),
        );
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return self::fromArray((array) $data);
    }
}
