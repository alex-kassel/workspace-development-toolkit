<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;

abstract class BaseWorkspaceCommand extends BaseCommand
{
    /**
     * Retrieve and validate the required workspace path argument.
     * Zero-ambiguity: provides concrete format example in prompt and error.
     */
    protected function getRequiredWorkspacePath(string $argument = 'path'): ?string
    {
        $rawPath = trim((string) $this->argument($argument));

        if ($rawPath === '') {
            $this->error('Workspace path cannot be empty.');
            $this->line('  <comment>How to fix:</comment> Provide a relative directory path for the workspace, e.g.:');
            $this->line("  <info>php artisan {$this->getName()} packages</info>");

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
