<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Events;

use AlexKassel\WorkspaceDevelopmentToolkit\Enums\DiagnosticSeverity;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class ConsoleDiagnosticDispatched
{
    /**
     * @param  array<string|int, string|array<int|string, string>>  $context
     * @param  array<int, string>  $remediationSteps
     */
    public function __construct(
        public string $code,
        public string $message,
        public DiagnosticSeverity $severity = DiagnosticSeverity::Error,
        public array $context = [],
        public array $remediationSteps = [],
        public ?string $agentGuidance = null,
        public ?OutputInterface $output = null,
    ) {}
}
