<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use Illuminate\Console\Command;

class WorkspaceDefaultCommand extends Command
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
        $rawPath = (string) $this->argument('path');
        $path = trim(preg_replace('#[/\\\\]+#', '/', $rawPath) ?? '', '/');

        if ($path === '') {
            $this->error('Workspace path cannot be empty.');
            $this->line('  <comment>How to fix:</comment> Provide the name of a registered workspace, e.g.:');
            $this->line('  <info>php artisan workspace:default packages</info>');

            return self::FAILURE;
        }

        if (! Workspace::setDefault($path)) {
            $available = array_keys(Workspace::all());
            $availableStr = empty($available) ? 'none' : implode(', ', $available);

            $this->error("Workspace [{$path}] is not registered. Available workspaces: [{$availableStr}].");
            $this->line('  <comment>How to fix:</comment> Register the workspace first:');
            $this->line("  <info>php artisan workspace:add {$path}</info>");
            $this->line('  Or select one from the list: <info>php artisan workspace:list</info>');

            return self::FAILURE;
        }

        $this->info("Workspace [{$path}] is now the default workspace.");

        $this->newLine();
        $this->line('  <comment>Hint:</comment> Any package created without --workspace will be placed in this workspace:');
        $this->line('  <info>php artisan package:make my-vendor/my-package</info>');

        return self::SUCCESS;
    }
}
