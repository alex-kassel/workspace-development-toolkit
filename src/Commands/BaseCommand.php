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
        $code = $e->errorCode();
        $this->error("[{$code}] {$e->getMessage()}");

        $steps = $e->remediationSteps();
        if (! empty($steps)) {
            $this->line('  <comment>How to fix:</comment>');
            foreach ($steps as $step) {
                $this->line("  • {$step}");
            }
        } elseif ($e->getSolution()) {
            $this->line("  <comment>How to fix:</comment> {$e->getSolution()}");
        }

        if ($e->agentInstructions() && ($this->output->isVerbose() || getenv('AGENT') !== false)) {
            $this->line("  <fg=gray>Agent guidance:</> {$e->agentInstructions()}");
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
