<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerManager;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceManager;
use Illuminate\Console\Command;

abstract class BaseWorkspaceCommand extends Command
{
    public function __construct(
        protected WorkspaceManager $workspace,
        protected ComposerManager $composer,
    ) {
        parent::__construct();
    }

    /**
     * Retrieve and validate the required workspace path argument.
     * Zero-ambiguity: provides concrete format example in prompt and error.
     */
    protected function getRequiredWorkspacePath(string $argument = 'path'): ?string
    {
        $rawPath = (string) $this->argument($argument);
        $path = $this->normalizeWorkspacePath($rawPath);

        if ($path === '') {
            $this->error('Workspace path cannot be empty.');
            $this->line('  <comment>How to fix:</comment> Provide a relative directory path for the workspace, e.g.:');
            $this->line("  <info>php artisan {$this->getName()} packages</info>");

            return null;
        }

        return $path;
    }

    /**
     * Standardized renderer for WorkspaceException errors and their actionable solutions.
     */
    protected function handleWorkspaceException(WorkspaceException $e): int
    {
        $this->error($e->getMessage());
        if ($e->getSolution()) {
            $this->line("  <comment>How to fix:</comment> {$e->getSolution()}");
        }

        return self::FAILURE;
    }

    /**
     * Normalize a raw workspace path (converts backslashes, collapses multiple slashes, trims).
     */
    protected function normalizeWorkspacePath(string $path): string
    {
        return trim(preg_replace('#[/\\\\]+#', '/', $path) ?? '', '/');
    }
}
