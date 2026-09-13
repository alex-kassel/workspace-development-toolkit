<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;

class WorkspaceRemoveCommand extends BaseWorkspaceCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workspace:remove {path : The workspace directory path}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove a workspace from composer.json and workspace.json (directory is preserved)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $path = $this->getRequiredWorkspacePath();
        if ($path === null) {
            return self::FAILURE;
        }

        try {
            $this->workspace->remove($path);
        } catch (WorkspaceException $e) {
            return $this->handleWorkspaceException($e);
        }

        $this->info("Workspace [{$path}] removed from configuration (composer.json and workspace.json).");

        $this->newLine();
        $this->line('  <comment>Hint:</comment> Workspace removal only unregisters the repository.');
        $this->line("  1. The physical directory [{$path}/] was preserved.");
        $this->line("     <fg=yellow;options=bold>CAUTION:</> Before deleting it manually, verify that git working trees inside [{$path}/] are clean and all commits have been pushed!");
        $this->line("  2. The ignore rule [/{$path}] has been cleanly removed from your .gitignore file.");

        return self::SUCCESS;
    }
}
