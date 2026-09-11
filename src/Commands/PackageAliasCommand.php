<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class PackageAliasCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:alias
        {package : The package name, short name, or current alias}
        {alias? : The new directory alias}
        {--as= : Alternate option to specify the new alias}
        {--alias= : Alternate option to specify the new alias}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Assign a directory alias to a package in a flat (fixed-vendor) workspace';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $package = (string) $this->argument('package');
        $rawAlias = (string) ($this->option('as') ?: $this->option('alias') ?: $this->argument('alias'));

        if (trim($package) === '') {
            $this->error('Package name is required.');
            $this->line('  <comment>Usage:</comment> php artisan package:alias <package> <alias>');

            return self::FAILURE;
        }

        if (trim($rawAlias) === '') {
            $this->error('Alias is required.');
            $this->line('  <comment>Usage:</comment>');
            $this->line("  <info>php artisan package:alias {$package} MyAlias</info>");
            $this->line("  <info>php artisan package:alias {$package} --as=MyAlias</info>");

            return self::FAILURE;
        }

        try {
            $result = Workspace::aliasPackage($package, $rawAlias);
        } catch (WorkspaceException $e) {
            $this->error($e->getMessage());
            if ($e->getSolution()) {
                $this->line("  <comment>How to fix:</comment> {$e->getSolution()}");
            }

            return self::FAILURE;
        }

        $oldPath = $result['old_path'];
        $newPath = $result['new_path'];
        $canonicalName = $result['canonical_name'];

        $this->info("Package [{$canonicalName}] successfully aliased to [{$rawAlias}] ({$newPath}).");

        // Refresh Composer autoloader to account for directory rename
        Process::path(base_path())->run(['composer', 'dump-autoload']);

        // Check if the chosen alias already exists elsewhere
        $duplicates = Workspace::findDuplicateAliases($rawAlias, $newPath);
        if (! empty($duplicates)) {
            $this->newLine();
            $this->warn("Notice: The alias/name [{$rawAlias}] is also used by another package:");
            foreach ($duplicates as $duplicate) {
                $this->line("  • {$duplicate}");
            }
            $this->newLine();
            $this->line("  <comment>Hint:</comment> Both packages will work normally in Composer, but resolving by short name '{$rawAlias}' will be ambiguous.");
            $this->line('  If you wish to differentiate them, you can assign a unique alias:');
            $this->line("  <info>php artisan package:alias {$newPath} UniqueAlias</info>");
        }

        $this->newLine();
        $this->line('  <comment>Next steps:</comment>');
        $this->line('  • Check registered packages: <info>php artisan workspace:list</info>');
        $this->line("  • Refer to this package:     <info>php artisan package:install {$rawAlias}</info>");

        return self::SUCCESS;
    }
}
