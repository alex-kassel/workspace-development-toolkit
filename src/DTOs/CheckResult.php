<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\DTOs;

final readonly class CheckResult
{
    public function __construct(
        public string $check,           // 'composer', 'pint', 'phpstan', 'tests', etc.
        public string $package,         // 'vendor/package'
        public string $status,          // 'passed', 'failed', 'skipped'
        public string $output,
        public float $durationSeconds = 0.0,
    ) {}

    public function isPassed(): bool
    {
        return $this->status === 'passed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isSkipped(): bool
    {
        return $this->status === 'skipped';
    }

    /**
     * @return array{check: string, package: string, status: string, output: string, duration_seconds: float}
     */
    public function toArray(): array
    {
        return [
            'check' => $this->check,
            'package' => $this->package,
            'status' => $this->status,
            'output' => $this->output,
            'duration_seconds' => $this->durationSeconds,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            check: (string) ($data['check'] ?? ''),
            package: (string) ($data['package'] ?? ''),
            status: (string) ($data['status'] ?? 'failed'),
            output: (string) ($data['output'] ?? ''),
            durationSeconds: (float) ($data['duration_seconds'] ?? 0.0),
        );
    }
}
