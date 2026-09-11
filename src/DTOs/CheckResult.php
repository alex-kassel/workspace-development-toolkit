<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\DTOs;

final readonly class CheckResult
{
    public function __construct(
        public string $check,           // 'composer', 'pint', 'phpstan', 'tests'
        public string $package,         // 'vendor/package'
        public string $status,          // 'passed', 'failed', 'skipped'
        public string $output,
        public float $durationSeconds,
    ) {}
}
