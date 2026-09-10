<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use Illuminate\Console\Command;

class WorkspaceListCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workspace:list';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all registered workspaces and their packages';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $data = Workspace::sync();
        $workspaces = $data['workspaces'];
        $default = $data['default'];

        if (empty($workspaces)) {
            $this->info('No workspaces registered.');
            $this->line('  <comment>How to fix:</comment> Register a workspace using:');
            $this->line('  <info>php artisan workspace:add packages</info>');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($workspaces as $workspace => $config) {
            $vendor = $config['vendor'] ?? null;
            $packages = $config['packages'] ?? [];

            $rows[] = [
                $workspace,
                $workspace === $default ? 'Yes' : 'No',
                $vendor ?? '(none / multi)',
                $vendor ? 'Flat' : 'Nested',
                count($packages),
                empty($packages) ? '(none)' : implode("\n", $packages),
            ];
        }

        $this->table(['Workspace', 'Default', 'Vendor', 'Structure', 'Packages Count', 'Packages'], $rows);

        $this->newLine();
        $this->line('  <comment>Hint:</comment> To create a new package: <info>php artisan package:make my-vendor/my-package</info>');
        $this->line('  To add another workspace: <info>php artisan workspace:add &lt;path&gt;</info>');

        return self::SUCCESS;
    }
}
