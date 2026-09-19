<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands\Concerns;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\ConsoleUxGuidance;
use Illuminate\Console\Command;
use Laravel\Prompts\ConfirmPrompt;
use Laravel\Prompts\SelectPrompt;
use Laravel\Prompts\TextPrompt;

/**
 * Trait providing unified interactive Prompts, graceful non-interactive degradation,
 * and structured guidance for human developers, AI agents, and CI runners.
 */
trait InteractsWithConsoleUx
{
    /**
     * Determine if command is running in a live interactive console environment.
     */
    public function isInteractiveEnvironment(): bool
    {
        if (! $this->input->isInteractive()) {
            return false;
        }

        if (function_exists('app') && app()->runningUnitTests()) {
            return false;
        }

        return true;
    }

    /**
     * Display a choice prompt using Laravel Prompts when interactive, or fallback to Symfony choice.
     *
     * @param  array<int|string, string>  $options
     */
    public function promptSelect(string $label, array $options, mixed $default = null): string
    {
        if ($this->isInteractiveEnvironment() && class_exists(SelectPrompt::class)) {
            return (string) \Laravel\Prompts\select(
                label: $label,
                options: $options,
                default: $default,
            );
        }

        return (string) $this->choice($label, array_values($options), $default);
    }

    /**
     * Display a text prompt using Laravel Prompts when interactive, or fallback to Symfony ask.
     */
    public function promptText(string $label, string $placeholder = '', string $default = '', bool $required = false): string
    {
        if ($this->isInteractiveEnvironment() && class_exists(TextPrompt::class)) {
            return (string) \Laravel\Prompts\text(
                label: $label,
                placeholder: $placeholder,
                default: $default,
                required: $required,
            );
        }

        return (string) ($this->ask($label, $default !== '' ? $default : null) ?? '');
    }

    /**
     * Display a confirmation prompt using Laravel Prompts when interactive, or fallback to Symfony confirm.
     */
    public function promptConfirm(string $label, bool $default = true): bool
    {
        if ($this->isInteractiveEnvironment() && class_exists(ConfirmPrompt::class)) {
            return \Laravel\Prompts\confirm(
                label: $label,
                default: $default,
            );
        }

        return $this->confirm($label, $default);
    }

    /**
     * Fail gracefully in non-interactive / CI / AI-agent mode with structured diagnostic guidance.
     *
     * @param  array<int|string, string>  $availableOptions
     * @param  array<int, string>  $remediationSteps
     */
    public function failWithGuidance(
        ConsoleUxGuidance|string $guidance,
        ?string $argument = null,
        array $availableOptions = [],
        ?string $usageExample = null,
        array $remediationSteps = [],
        ?string $agentInstructions = null,
    ): int {
        $data = $guidance instanceof ConsoleUxGuidance
            ? $guidance
            : new ConsoleUxGuidance(
                message: $guidance,
                argument: $argument,
                availableOptions: $availableOptions,
                usageExample: $usageExample,
                remediationSteps: $remediationSteps,
                agentInstructions: $agentInstructions,
            );

        $this->newLine();
        $this->error("✘ Error: {$data->message}");

        if ($data->argument !== null) {
            $this->line("  <comment>Missing Required Argument:</comment> <info>{$data->argument}</info>");
        }

        if (! empty($data->availableOptions)) {
            $this->line('  <comment>Available Options:</comment>');
            foreach ($data->availableOptions as $key => $option) {
                $label = is_string($key) && ! is_numeric($key) ? "{$key} ({$option})" : $option;
                $this->line("    • <info>{$label}</info>");
            }
        }

        if ($data->usageExample !== null) {
            $this->line("  <comment>Usage Example:</comment> <info>{$data->usageExample}</info>");
        }

        if (! empty($data->remediationSteps)) {
            $this->line('  <comment>Remediation Steps:</comment>');
            foreach ($data->remediationSteps as $step) {
                $this->line("    1. {$step}");
            }
        }

        if ($data->agentInstructions !== null) {
            $this->line("  <comment>AI Agent Guidance:</comment> {$data->agentInstructions}");
        }

        $this->newLine();

        return Command::FAILURE;
    }

    /**
     * Determine if command caller requested machine-readable JSON output.
     */
    public function wantsJsonOutput(): bool
    {
        return $this->hasOption('json') && (bool) $this->option('json');
    }

    /**
     * Output machine-readable JSON result and exit successfully.
     */
    public function renderJsonResult(mixed $data): int
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->output->writeln($encoded ?: '{}');

        return Command::SUCCESS;
    }
}
