<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\ComposerProcessException;
use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PackageUninstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:uninstall {name : Package name (vendor/package or short name for fixed-vendor workspace)} {--dev : Uninstall from require-dev}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Uninstall a package from the application via Composer';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $rawName = (string) $this->argument('name');
        $normalizedInput = str_replace('\\', '/', trim($rawName));

        $canonicalName = Workspace::resolveCanonicalPackageName($normalizedInput);

        $validation = Workspace::validatePackageName($canonicalName);
        if (! $validation['isValid']) {
            $this->error($validation['error'] ?? "Invalid package name [{$rawName}].");
            if ($validation['suggestion'] !== null) {
                $this->line('  <comment>How to fix:</comment> Did you mean:');
                $this->line("  <info>php artisan package:uninstall {$validation['suggestion']}".($this->option('dev') ? ' --dev' : '').'</info>');
            } else {
                $this->line('  <comment>How to fix:</comment> Specify the full package name:');
                $this->line('  <info>php artisan package:uninstall my-vendor/my-package</info>');
            }

            return self::FAILURE;
        }

        $name = $validation['fullName'];

        if ($name !== $rawName) {
            $this->line("  <comment>Notice:</comment> Resolved package [{$rawName}] to Composer package [{$name}].");
        }

        $composer = json_decode(File::get(base_path('composer.json')), true) ?: [];
        $isRequire = isset($composer['require'][$name]);
        $isRequireDev = isset($composer['require-dev'][$name]);

        if (! $isRequire && ! $isRequireDev) {
            $this->error("Package [{$name}] is not installed in root composer.json.");
            $this->line('  <comment>How to fix:</comment> Verify installed packages with:');
            $this->line('  <info>composer show</info>');
            $this->line('  If you wanted to delete the local package files entirely, use:');
            $this->line("  <info>php artisan package:delete {$name}</info>");

            return self::FAILURE;
        }

        $isDev = (bool) $this->option('dev') || $isRequireDev;

        $args = ['remove', $name];
        if ($isDev) {
            $args[] = '--dev';
        }

        $this->info("Uninstalling [{$name}] via Composer...");

        try {
            Workspace::runComposer($args);
        } catch (ComposerProcessException $e) {
            $this->error("Failed to uninstall package [{$name}] via Composer.");
            $this->line("  <comment>Composer output:</comment>\n".trim($e->output));
            $this->line('  <comment>How to fix:</comment> Check for dependencies blocking removal or run manually with verbose output:');
            $this->line("  <info>composer remove {$name}".($isDev ? ' --dev' : '').' -v</info>');

            return self::FAILURE;
        }

        $this->info("Package [{$name}] uninstalled successfully. Physical files in workspace remain intact.");

        $this->newLine();
        $this->line('  <comment>Hint:</comment> To re-install this package later:');
        $this->line("  <info>php artisan package:install {$name}".($isDev ? ' --dev' : '').'</info>');
        $this->line('  Or to permanently delete the physical files from disk:');
        $this->line("  <info>php artisan package:delete {$name}</info>");

        return self::SUCCESS;
    }
}
