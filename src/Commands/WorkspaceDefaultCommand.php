<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;

class WorkspaceDefaultCommand extends BaseWorkspaceCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workspace:default {path : The workspace directory path to set as default}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set the default workspace';

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
            $this->workspace->setDefault($path);
        } catch (WorkspaceException $e) {
            return $this->handleWorkspaceException($e);
        }

        $this->info("Workspace [{$path}] is now the default workspace.");

        $this->newLine();
        $this->line('  <comment>Hint:</comment> Any package created without --workspace will be placed in this workspace:');
        $this->line('  <info>php artisan package:make my-vendor/my-package</info>');

        return self::SUCCESS;
    }
}
