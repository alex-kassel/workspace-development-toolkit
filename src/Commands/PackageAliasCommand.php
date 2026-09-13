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
        {package? : Package name in vendor/package format (e.g. acme/my-pkg)}
        {alias? : New directory alias name (e.g. MyPackage)}';

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
        $package = $this->getRequiredPackage();
        if ($package === null) {
            return self::FAILURE;
        }

        $rawAlias = trim((string) $this->argument('alias'));

        if ($rawAlias === '' && $this->input->isInteractive()) {
            $rawAlias = trim((string) $this->ask("Please enter the new directory alias for [{$package}] (e.g. MyPackage):"));
        }

        if ($rawAlias === '') {
            $this->error('Alias is required.');
            $this->line("  <comment>Usage:</comment>   php artisan package:alias {$package} <alias>");
            $this->line("  <comment>Example:</comment> php artisan package:alias {$package} MyPackage");

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
