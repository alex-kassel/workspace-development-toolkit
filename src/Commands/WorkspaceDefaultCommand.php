<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceNotFoundException;
use Laravel\Prompts\Prompt;

class WorkspaceDefaultCommand extends BaseWorkspaceCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workspace:default {path? : The workspace directory path to set as default}';

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
        } catch (WorkspaceNotFoundException $e) {
            if ($this->input->isInteractive() && @stream_isatty(STDIN) && ! empty($e->available)) {
                try {
                    $usePrompt = class_exists(Prompt::class);
                    $confirm = $usePrompt
                        ? \Laravel\Prompts\confirm("Workspace [{$path}] is not registered. Choose from available workspaces instead?", true)
                        : $this->confirm("Workspace [{$path}] is not registered. Choose from available workspaces instead?", true);

                    if ($confirm) {
                        $selected = $usePrompt
                            ? \Laravel\Prompts\select('Select default workspace:', $e->available)
                            : $this->choice('Select default workspace:', $e->available, 0);

                        $path = (string) $selected;
                        $this->workspace->setDefault($path);
                    } else {
                        return $this->handleWorkspaceException($e);
                    }
                } catch (\Throwable) {
                    return $this->handleWorkspaceException($e);
                }
            } else {
                return $this->handleWorkspaceException($e);
            }
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
