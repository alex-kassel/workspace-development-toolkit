<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\ComposerProcessException;
use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use Illuminate\Console\Command;

class PackageInstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:install {name : Package name (vendor/package or short name for fixed-vendor workspace)} {--dev : Install package into require-dev} {--remote : Allow installing non-local package from Composer remote repositories}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install a local workspace package into the application via Composer';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $rawName = (string) $this->argument('name');
        $isDev = (bool) $this->option('dev');
        $allowRemote = (bool) $this->option('remote');
        $normalizedInput = str_replace('\\', '/', trim($rawName));

        $canonicalName = Workspace::resolveCanonicalPackageName($normalizedInput);

        $validation = Workspace::validatePackageName($canonicalName);
        if (! $validation['isValid']) {
            $this->error($validation['error'] ?? "Invalid package name [{$rawName}].");
            if ($validation['suggestion'] !== null) {
                $this->line('  <comment>How to fix:</comment> Did you mean:');
                $this->line("  <info>php artisan package:install {$validation['suggestion']}".($isDev ? ' --dev' : '').'</info>');
            } else {
                $this->line('  <comment>How to fix:</comment> Specify the full package name:');
                $this->line('  <info>php artisan package:install my-vendor/my-package</info>');
                $this->line('  Or check registered workspaces: <info>php artisan workspace:list</info>');
            }

            return self::FAILURE;
        }

        $name = $validation['fullName'];

        if ($name !== $rawName) {
            $this->line("  <comment>Notice:</comment> Resolved package [{$rawName}] to Composer package [{$name}].");
        }

        $packagePath = Workspace::findPackagePath($name);

        if (! $packagePath) {
            if (! $allowRemote) {
                $this->error("Package [{$name}] was not found in any registered workspace.");
                $this->line('  <comment>How to fix:</comment> Verify that the package exists in one of your workspaces:');
                $this->line('  <info>php artisan workspace:list</info>');
                $this->line('  Or create the package first:');
                $this->line("  <info>php artisan package:make {$name}</info>");
                $this->line('  If you intentionally wish to install a remote Composer package, re-run with --remote:');
                $this->line("  <info>php artisan package:install {$name}".($isDev ? ' --dev' : '').' --remote</info>');

                return self::FAILURE;
            }

            $this->warn("Notice: Package [{$name}] was not found locally. Installing from remote Composer repositories via [--remote].");
        }

        $packageConstraint = $packagePath ? "{$name}:@dev" : $name;
        $args = ['require', $packageConstraint];

        if ($isDev) {
            $args[] = '--dev';
        }

        $this->info("Installing [{$name}] via Composer...");

        try {
            Workspace::runComposer($args);
        } catch (ComposerProcessException $e) {
            $this->error("Failed to install package [{$name}] via Composer.");
            $this->line("  <comment>Composer output:</comment>\n".trim($e->output));
            $this->line('  <comment>How to fix:</comment> If this is a local package, verify that it was created first:');
            $this->line("  <info>php artisan package:make {$name}</info>");
            $this->line('  Also check your registered workspaces: <info>php artisan workspace:list</info>');

            return self::FAILURE;
        }

        $targetSection = $isDev ? 'require-dev' : 'require';
        $this->info("Package [{$name}] installed successfully into [{$targetSection}].");

        $this->newLine();
        $this->line('  <comment>Hint:</comment> To uninstall this package from composer without deleting files:');
        $this->line("  <info>php artisan package:uninstall {$name}</info>");
        $this->line('  Or to permanently delete it:');
        $this->line("  <info>php artisan package:delete {$name}</info>");

        return self::SUCCESS;
    }
}
