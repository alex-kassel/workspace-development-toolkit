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
    protected function getRequiredWorkspacePath(string $argument = 'path', bool $mustExist = true, bool $mustNotExist = false): ?string
    {
        $rawPath = trim((string) $this->argument($argument));

        if ($rawPath === '') {
            $workspaces = $this->workspace->all();
            $available = array_keys($workspaces);

            if ($this->isInteractive()) {
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
                        if ($usePrompt) {
                            $entered = \Laravel\Prompts\text(
                                label: 'Enter the workspace directory path (e.g. packages):',
                                placeholder: 'packages',
                                required: true,
                                validate: function (string $value) use ($mustNotExist, $available) {
                                    $trimmed = trim(preg_replace('#[/\\\\]+#', '/', $value) ?? '', '/');
                                    if ($trimmed === '') {
                                        return 'Workspace path cannot be empty.';
                                    }

                                    try {
                                        $normalized = $this->workspace->normalizeWorkspacePath($trimmed);
                                    } catch (WorkspaceException $e) {
                                        return $e->getMessage();
                                    }

                                    if ($mustNotExist && in_array($normalized, $available, true)) {
                                        $list = implode(', ', $available);

                                        return "Workspace [{$normalized}] is already registered. Existing workspaces: [{$list}]. Please enter a different path.";
                                    }

                                    return null;
                                }
                            );

                            return $this->workspace->normalizeWorkspacePath(trim((string) $entered));
                        }

                        while (true) {
                            $entered = $this->ask('Enter the workspace directory path (e.g. packages):');
                            if ($entered === null || trim((string) $entered) === '') {
                                break;
                            }
                            $trimmed = trim(preg_replace('#[/\\\\]+#', '/', (string) $entered) ?? '', '/');

                            try {
                                $normalized = $this->workspace->normalizeWorkspacePath($trimmed);
                            } catch (WorkspaceException $e) {
                                $this->error($e->getMessage());

                                continue;
                            }

                            if ($mustNotExist && in_array($normalized, $available, true)) {
                                $this->warn("Workspace [{$normalized}] is already registered. Existing workspaces: ".implode(', ', $available));

                                continue;
                            }

                            return $normalized;
                        }
                    }
                } catch (\Throwable) {
                    return null;
                }

                return null;
            }

            if ($mustNotExist) {
                $example = 'packages';
                if (in_array('packages', $available, true)) {
                    $example = in_array('modules', $available, true) ? 'labs' : 'modules';
                }
            } else {
                $example = ! empty($available) ? $available[0] : 'packages';
            }

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
