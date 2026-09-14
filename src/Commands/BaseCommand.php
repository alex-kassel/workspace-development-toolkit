<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Enums\DiagnosticSeverity;
use AlexKassel\WorkspaceDevelopmentToolkit\Events\ConsoleDiagnosticDispatched;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerManager;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ConsoleUiRenderer;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceManager;
use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;

abstract class BaseCommand extends Command
{
    public function __construct(
        protected WorkspaceManager $workspace,
        protected ComposerManager $composer,
    ) {
        parent::__construct();
    }

    /**
     * Standardized renderer for WorkspaceException errors and their actionable solutions.
     */
    protected function handleWorkspaceException(WorkspaceException $e): int
    {
        $steps = $e->remediationSteps();
        if (empty($steps) && $e->getSolution()) {
            $steps = [$e->getSolution()];
        }

        $agentGuidance = ($this->output->isVerbose() || getenv('AGENT') !== false)
            ? $e->agentInstructions()
            : null;

        $this->dispatchDiagnostic(
            code: $e->errorCode(),
            message: $e->getMessage(),
            severity: $e->severity(),
            context: $e->diagnosticContext(),
            remediationSteps: $steps,
            agentGuidance: $agentGuidance,
        );

        return self::FAILURE;
    }

    /**
     * Dispatch a structured console diagnostic event.
     *
     * @param  array<string|int, string|array<int|string, string>>  $context
     * @param  array<int, string>  $remediationSteps
     */
    protected function dispatchDiagnostic(
        string $code,
        string $message,
        DiagnosticSeverity $severity = DiagnosticSeverity::Error,
        array $context = [],
        array $remediationSteps = [],
        ?string $agentGuidance = null,
    ): void {
        $event = new ConsoleDiagnosticDispatched(
            code: $code,
            message: $message,
            severity: $severity,
            context: $context,
            remediationSteps: $remediationSteps,
            agentGuidance: $agentGuidance,
            output: $this->output,
        );

        /** @var Dispatcher|null $dispatcher */
        $dispatcher = app()->bound(Dispatcher::class) ? app(Dispatcher::class) : null;
        if ($dispatcher !== null) {
            $dispatcher->dispatch($event);
        } else {
            app(ConsoleUiRenderer::class)->render($event, $this->output);
        }
    }

    /**
     * Sanitize raw string input (converts Windows backslashes to forward slashes and trims).
     */
    protected function sanitizeInput(string $input): string
    {
        return str_replace('\\', '/', trim($input));
    }
}
