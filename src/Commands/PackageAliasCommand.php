<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;

class PackageAliasCommand extends BasePackageCommand
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
        $package = trim((string) $this->argument('package'));
        if ($package === '') {
            $this->error('Package name is required.');
            $this->line('  <comment>Usage:</comment> php artisan package:alias <package> <alias>');

            return self::FAILURE;
        }

        $rawAlias = trim((string) ($this->option('as') ?: $this->option('alias') ?: $this->argument('alias')));

        if ($rawAlias === '' && $this->input->isInteractive()) {
            $rawAlias = trim((string) $this->ask("Please enter the new directory alias for [{$package}]:"));
        }

        if ($rawAlias === '') {
            $this->error('Alias is required.');
            $this->line('  <comment>Usage:</comment>');
            $this->line("  <info>php artisan package:alias {$package} MyAlias</info>");
            $this->line("  <info>php artisan package:alias {$package} --as=MyAlias</info>");

            return self::FAILURE;
        }

        try {
            $result = $this->workspace->aliasPackage($package, $rawAlias);
        } catch (WorkspaceException $e) {
            return $this->handleWorkspaceException($e);
        }

        $this->info("Package [{$result->canonicalName}] successfully aliased to [{$result->alias}] ({$result->newPath}).");

        $this->warnIfDuplicateAlias($result->alias, $result->newPath);

        $this->newLine();
        $this->line('  <comment>Next steps:</comment>');
        $this->line('  • Check registered packages: <info>php artisan workspace:list</info>');
        $this->line("  • Verify package status:     <info>php artisan package:check {$result->alias}</info>");

        return self::SUCCESS;
    }
}
