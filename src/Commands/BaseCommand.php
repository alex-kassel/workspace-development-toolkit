<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerManager;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceManager;
use Illuminate\Console\Command;

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
        $this->error($e->getMessage());
        if ($e->getSolution()) {
            $this->line("  <comment>How to fix:</comment> {$e->getSolution()}");
        }

        return self::FAILURE;
    }

    /**
     * Sanitize raw string input (converts Windows backslashes to forward slashes and trims).
     */
    protected function sanitizeInput(string $input): string
    {
        return str_replace('\\', '/', trim($input));
    }
}
