<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\DTOs;

final readonly class GitDiagnosticResult
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

    public function isAuthIssue(): bool
    {
        return in_array($this->type, ['ssh_auth', 'https_auth', 'not_found_or_private'], true);
    }
}
