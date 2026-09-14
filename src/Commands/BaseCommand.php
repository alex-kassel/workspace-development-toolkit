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
use Laravel\Prompts\Prompt;
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

            $cmd = $this->getName() ?? 'command';
            $message = $e->getMessage();
            $synopsis = 'php artisan '.$this->getSynopsis();
            $desc = $this->getDescription();

            $context = [
                'Expected usage' => $synopsis,
            ];

            if ($desc !== '') {
                $context['Description'] = $desc;
            }

            $context['Details'] = $message;

            $this->dispatchDiagnostic(
                code: 'CMD_ARGUMENT_ERROR',
                message: "Invalid or unexpected arguments provided to [{$cmd}].",
                severity: DiagnosticSeverity::Error,
                context: $context,
                remediationSteps: [
                    "Inspect command options and syntax: php artisan help {$cmd}",
                    'View toolkit overview and examples: php artisan workspace:help',
                ],
                agentGuidance: "Check the command's expected synopsis: 'php artisan help {$cmd}'."
            );

            if ($input->isInteractive() && @stream_isatty(STDIN)) {
                try {
                    $usePrompt = class_exists(Prompt::class);
                    $showHelp = $usePrompt
                        ? \Laravel\Prompts\confirm("Would you like to view the complete help guide for [{$cmd}] now?", default: true)
                        : $this->confirm("Would you like to view the complete help guide for [{$cmd}] now?", true);

                    if ($showHelp) {
                        $this->call('help', ['command_name' => $cmd]);
                    }
                } catch (\Throwable) {
                    // Non-interactive or prompt cancelled
                }
            }

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
