<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\DTOs;

final readonly class ConsoleUxGuidance
{
    /**
     * @param  array<int|string, string>  $availableOptions
     * @param  array<int, string>  $remediationSteps
     */
    public function __construct(
        public string $message,
        public ?string $argument = null,
        public array $availableOptions = [],
        public ?string $usageExample = null,
        public array $remediationSteps = [],
        public ?string $agentInstructions = null,
    ) {}
}
