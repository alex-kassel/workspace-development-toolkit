<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Enums\DiagnosticSeverity;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
use Laravel\Prompts\Prompt;

abstract class BaseWorkspaceCommand extends BaseCommand
{
    /**
     * Retrieve and validate the required workspace path argument.
     * Zero-ambiguity: provides concrete format example in prompt and error.
     */
    protected function getRequiredWorkspacePath(string $argument = 'path', bool $mustExist = true): ?string
    {
        $rawPath = trim((string) $this->argument($argument));

        if ($rawPath === '') {
            $workspaces = $this->workspace->all();
            $available = array_keys($workspaces);

            if ($this->input->isInteractive() && @stream_isatty(STDIN)) {
                try {
                    if ($mustExist && ! empty($available)) {
                        $usePrompt = class_exists(Prompt::class);
                        $selected = $usePrompt
                            ? \Laravel\Prompts\select(label: 'Select a workspace:', options: $available)
                            : $this->choice('Select a workspace:', $available, 0);

                        return $this->workspace->normalizeWorkspacePath((string) $selected);
                    }

                    if (! $mustExist) {
                        $usePrompt = class_exists(Prompt::class);
                        $entered = $usePrompt
                            ? \Laravel\Prompts\text(label: 'Enter the workspace directory path (e.g. packages):', required: true)
                            : $this->ask('Enter the workspace directory path (e.g. packages):');

                        if ($entered !== null && trim((string) $entered) !== '') {
                            return $this->workspace->normalizeWorkspacePath(trim((string) $entered));
                        }
                    }
                } catch (\Throwable) {
                    // Fall through to non-interactive diagnostic dispatch
                }
            }

            $example = ! empty($available) ? $available[0] : 'packages';

            $this->dispatchDiagnostic(
                code: 'CMD_ARGUMENT_REQUIRED',
                message: 'Workspace path cannot be empty.',
                severity: DiagnosticSeverity::Error,
                context: [
                    'Available workspaces' => empty($available) ? ['(none registered)'] : $available,
                ],
                remediationSteps: [
                    "php artisan {$this->getName()} {$example}",
                ],
                agentGuidance: "Provide a workspace path as an argument: 'php artisan {$this->getName()} <path>'."
            );

            return null;
        }

        try {
            return $this->workspace->normalizeWorkspacePath($rawPath);
        } catch (WorkspaceException $e) {
            $this->handleWorkspaceException($e);

            return null;
        }
    }
}
