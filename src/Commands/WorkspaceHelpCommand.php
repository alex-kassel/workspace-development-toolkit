<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

class WorkspaceHelpCommand extends BaseWorkspaceCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workspace:help';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display comprehensive guide, architectural paradigms and examples for the Workspace Development Toolkit';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->line('');
        $this->line('  <bg=blue;fg=white;options=bold> WORKSPACE DEVELOPMENT TOOLKIT </bg=blue;fg=white;options=bold>  <fg=gray>Developer & Multi-Workspace Manager</>');
        $this->line('');
        $this->line('  <comment>OVERVIEW:</comment>');
        $this->line('  This toolkit turns your Laravel application into a mission control center for building,');
        $this->line('  testing, and managing multiple local packages across isolated git-ready workspaces.');
        $this->line('');
        $this->line('  <comment>TWO WORKSPACE PARADIGMS:</comment>');
        $this->line('  <info>1. Multi-Vendor Workspace</info> (Nested Structure)');
        $this->line('     • Command:   <fg=yellow>php artisan workspace:add packages</>');
        $this->line('     • On Disk:   <fg=cyan>packages/{vendor}/{package}/</> (2 levels)');
        $this->line('     • Use Case:  Public libraries, third-party forks, or multiple vendors.');
        $this->line('     • Creation:  <fg=yellow>php artisan package:make my-vendor/my-package --workspace=packages</>');
        $this->line('');
        $this->line('  <info>2. Fixed-Vendor Workspace</info> (Flat Structure)');
        $this->line('     • Command:   <fg=yellow>php artisan workspace:add labs --vendor=alex-kassel-labs</>');
        $this->line('     • On Disk:   <fg=cyan>labs/{package}/</> (1 level, no redundant vendor folder!)');
        $this->line('     • Use Case:  Internal company modules, customer apps, or domain services.');
        $this->line('     • Creation:  <fg=yellow>php artisan package:make my-module --workspace=labs</>');
        $this->line('                  <fg=gray>Composer name automatically becomes "alex-kassel-labs/my-module"</>');
        $this->line('');
        $this->line('  <comment>AVAILABLE COMMANDS:</comment>');

        $commands = [
            ['workspace:add <path> [--vendor=] [--default]', 'Register a new workspace directory into composer and gitignore.'],
            ['workspace:list', 'Display all registered workspaces, their vendors, structure mode, and packages.'],
            ['workspace:default <path>', 'Set the default workspace for creating new packages.'],
            ['workspace:remove <path>', 'Unregister workspace repository from Composer and manifest (files kept).'],
            ['workspace:help', 'Display this interactive guide and cheat sheet.'],
            ['package:make <name> [--workspace=] [--install] [--dev]', 'Scaffold a new local Laravel package with ServiceProvider and manifest.'],
            ['package:clone [package] [--self] [--workspace=]', 'Clone a Git/GitHub package into workspace and optionally symlink.'],
            ['package:install <name> [--dev]', 'Symlink a local workspace package into root Laravel application.'],
            ['package:alias <name> <alias> [--as=]', 'Assign a directory alias to a package in a flat workspace.'],
            ['package:uninstall <name> [--dev]', 'Remove package from root composer.json requirements (files kept).'],
            ['package:delete <name> [--force]', 'Completely delete a package from disk, manifest, and Composer.'],
            ['php workspace restore', 'Standalone root CLI (runs before composer install on fresh machines).'],
            ['php workspace status', 'Check physical presence of workspace packages on disk.'],
        ];

        foreach ($commands as [$sig, $desc]) {
            $this->line(sprintf('  <fg=green>%-58s</> <fg=white>%s</>', $sig, $desc));
        }

        $this->line('');
        $this->line('  <comment>COMMON WORKFLOW EXAMPLES:</comment>');
        $this->line('  <fg=gray># Create and immediately install a multi-vendor dev package:</>');
        $this->line('  <fg=yellow>php artisan package:make my-vendor/my-package --install --dev</>');
        $this->line('');
        $this->line('  <fg=gray># Create a client-specific workspace and flat module:</>');
        $this->line('  <fg=yellow>php artisan workspace:add clients/acme --vendor=acme-corp</>');
        $this->line('  <fg=yellow>php artisan package:make billing --workspace=clients/acme --install</>');
        $this->line('');
        $this->line('  <fg=gray># View detailed help for any specific command:</>');
        $this->line('  <fg=yellow>php artisan help package:make</>');
        $this->line('  <fg=yellow>php artisan help workspace:add</>');
        $this->line('');

        return self::SUCCESS;
    }
}
