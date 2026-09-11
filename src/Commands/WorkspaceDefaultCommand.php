<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
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

        try {
            Workspace::setDefault($path);
        } catch (WorkspaceException $e) {
            $this->error($e->getMessage());
            if ($e->getSolution()) {
                $this->line("  <comment>How to fix:</comment> {$e->getSolution()}");
            }

            return self::FAILURE;
        }

        $this->info("Workspace [{$path}] is now the default workspace.");

        $this->newLine();
        $this->line('  <comment>Hint:</comment> Any package created without --workspace will be placed in this workspace:');
        $this->line('  <info>php artisan package:make my-vendor/my-package</info>');

        return self::SUCCESS;
    }
}
