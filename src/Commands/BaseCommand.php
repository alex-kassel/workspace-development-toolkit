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
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Events\Dispatcher;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class BaseCommand extends Command
{
    public function __construct(
        protected WorkspaceManager $workspace,
        protected ComposerManager $composer,
    ) {
        parent::__construct();
    }

    /**
     * Run the console command, capturing any Symfony validation or syntax exceptions.
     */
    public function run(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::run($input, $output);
        } catch (ExceptionInterface $e) {
            $this->input = $input;
            $this->output = new OutputStyle($input, $output);

            $message = $e->getMessage();
            if (str_contains($message, 'Too many arguments')) {
                $this->dispatchDiagnostic(
                    code: 'CMD_TOO_MANY_ARGUMENTS',
                    message: "Too many arguments provided to [{$this->getName()}].",
                    severity: DiagnosticSeverity::Error,
                    context: [
                        'Symfony error' => $message,
                    ],
                    remediationSteps: [
                        'Provide only the arguments defined in the command signature.',
                        'If you used an unquoted shell wildcard (*), quote it or specify a single path: "pattern" or single-directory.',
                        "View command help and syntax: php artisan help {$this->getName()}",
                    ],
                    agentGuidance: "Too many arguments were passed to '{$this->getName()}'. In bash/zsh, unquoted wildcards like '*' expand to all filenames in the working directory before PHP receives them. Quote the argument or provide a single target."
                );

                return self::FAILURE;
            }

            $this->dispatchDiagnostic(
                code: 'CMD_SYNTAX_ERROR',
                message: $message,
                severity: DiagnosticSeverity::Error,
                remediationSteps: [
                    "View command help and syntax: php artisan help {$this->getName()}",
                ],
                agentGuidance: "Command syntax error. Check 'php artisan help {$this->getName()}'."
            );

            return self::FAILURE;
        }
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
