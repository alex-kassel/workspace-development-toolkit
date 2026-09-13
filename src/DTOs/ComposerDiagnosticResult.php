<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\DTOs;

final readonly class ComposerDiagnosticResult
{
    /**
     * @param  array<int, string>  $actionableSteps
     */
    public function __construct(
        public string $type,
        public string $title,
        public string $explanation,
        public array $actionableSteps = [],
        public ?string $rawOutput = null,
    ) {}

    public function isStabilityIssue(): bool
    {
        return $this->type === 'minimum_stability';
    }

    public function isNetworkIssue(): bool
    {
        return $this->type === 'network_offline';
    }
}
