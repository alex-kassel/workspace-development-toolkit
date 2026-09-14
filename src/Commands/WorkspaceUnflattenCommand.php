<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceNotFoundException;

class WorkspaceUnflattenCommand extends BaseWorkspaceCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workspace:unflatten
        {path? : The workspace directory path (e.g. app/Domains/ISS)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Unflatten a workspace directory structure into a multi-vendor nested layout';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $path = $this->getRequiredWorkspacePath();
        if ($path === null) {
            return self::FAILURE;
        }

        if (! array_key_exists($path, $this->workspace->all())) {
            return $this->handleWorkspaceException(
                new WorkspaceNotFoundException($path, array_keys($this->workspace->all()))
            );
        }

        try {
            $unflattened = $this->workspace->unflatten($path);
        } catch (WorkspaceException $e) {
            return $this->handleWorkspaceException($e);
        }

        $this->info("Workspace [{$path}] unflattened successfully into multi-vendor nested layout.");
        $this->line("  • Updated composer.json path repository pattern to: <info>{$path}/*/*</info>");
        $this->line("  • Workspace manifest saved to: <info>{$path}/workspace.json</info>");

        if (! empty($unflattened)) {
            $this->line('  • Relocated packages into vendor directories: '.implode(', ', $unflattened));
        }

        $this->newLine();
        $this->line('  <comment>Proactive hint:</comment> Packages are now organized as [{$path}/<vendor>/<package>].');
        $this->line("  <info>php artisan package:make my-vendor/my-package --workspace={$path}</info>");

        return self::SUCCESS;
    }
}
