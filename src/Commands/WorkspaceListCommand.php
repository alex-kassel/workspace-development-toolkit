<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use Composer\InstalledVersions;
use Illuminate\Console\Command;

class WorkspaceListCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workspace:list {--sync : Rescan physical workspace directories and synchronize workspace.json}';

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
        $data = $this->option('sync') ? Workspace::sync() : Workspace::load();
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

            $formattedPackages = array_map(function ($pkg) use ($workspace) {
                $rawName = is_array($pkg) ? ($pkg['name'] ?? '') : (string) $pkg;
                $alias = is_array($pkg) ? ($pkg['alias'] ?? null) : null;

                $canonical = Workspace::resolveCanonicalPackageName($rawName, $workspace);

                $installed = class_exists(InstalledVersions::class)
                    && InstalledVersions::isInstalled($canonical);

                $version = null;
                if ($installed) {
                    try {
                        $version = InstalledVersions::getPrettyVersion($canonical);
                    } catch (\Throwable) {
                        $version = null;
                    }
                }

                $vendorSymlinkPath = base_path("vendor/{$canonical}");
                $isLinked = is_link($vendorSymlinkPath);

                $statusParts = [];
                if ($alias) {
                    $statusParts[] = "as: {$alias}";
                }

                if ($installed) {
                    $symlinkInfo = $isLinked ? 'linked' : 'not linked';
                    $versionInfo = $version ? "v: {$version}" : 'installed';
                    $statusParts[] = "installed ({$versionInfo}, {$symlinkInfo})";
                } else {
                    $statusParts[] = 'not installed';
                }

                $suffix = ! empty($statusParts) ? ' ['.implode('; ', $statusParts).']' : '';

                return $rawName.$suffix;
            }, $packages);

            $rows[] = [
                $workspace,
                $workspace === $default ? 'Yes' : 'No',
                $vendor ?? '(none / multi)',
                $vendor ? 'Flat' : 'Nested',
                count($packages),
                empty($packages) ? '(none)' : implode("\n", $formattedPackages),
            ];
        }

        $this->table(['Workspace', 'Default', 'Vendor', 'Structure', 'Packages Count', 'Packages'], $rows);

        $this->newLine();
        $this->line('  <comment>Hint:</comment> To create a new package: <info>php artisan package:make my-vendor/my-package</info>');
        $this->line('  To add another workspace: <info>php artisan workspace:add &lt;path&gt;</info>');

        return self::SUCCESS;
    }
}
