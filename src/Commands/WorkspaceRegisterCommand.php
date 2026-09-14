<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Enums\DiagnosticSeverity;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
use Illuminate\Support\Str;

class WorkspaceRegisterCommand extends BaseWorkspaceCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workspace:register
        {path? : The workspace directory path (e.g. packages or app/Domains/ISS)}
        {--vendor= : Optional default vendor name for flat single-word packages}
        {--default : Set as default workspace}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Register a local workspace in composer.json, .gitignore and workspace.json';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $path = $this->getRequiredWorkspacePath(mustExist: false);
        if ($path === null) {
            return self::FAILURE;
        }

        $isDefault = (bool) $this->option('default');
        $rawVendor = (string) $this->option('vendor');

        $vendor = null;
        if ($rawVendor !== '') {
            $vendor = Str::slug($rawVendor);
            if ($vendor === '') {
                $this->error("Invalid vendor [{$rawVendor}]. Vendor may only contain alphanumeric characters.");
                $this->line('  <comment>How to fix:</comment> Provide a valid vendor slug, e.g.:');
                $this->line("  <info>php artisan workspace:register {$path} --vendor=my-vendor</info>");

                return self::FAILURE;
            }

            if ($vendor !== $rawVendor) {
                $this->line("  <comment>Notice:</comment> Converted vendor [{$rawVendor}] to normalized Composer format [{$vendor}].");
            }
        }

        try {
            if (array_key_exists($path, $this->workspace->all())) {
                $this->dispatchDiagnostic(
                    code: 'WS_WORKSPACE_ALREADY_REGISTERED',
                    message: "Workspace [{$path}] is already registered.",
                    severity: DiagnosticSeverity::Warning,
                    context: [
                        'Registered workspaces' => array_keys($this->workspace->all()),
                    ],
                    remediationSteps: [
                        'View registered workspaces:            php artisan workspace:list',
                        "To flatten into single-vendor layout:  php artisan workspace:flatten {$path} <vendor>",
                        "To unflatten into multi-vendor layout: php artisan workspace:unflatten {$path}",
                        "To unregister this workspace:          php artisan workspace:unregister {$path}",
                    ],
                    agentGuidance: 'Workspace is already registered. To change its layout or vendor, use workspace:flatten or workspace:unflatten.'
                );

                return self::FAILURE;
            }

            $this->workspace->add($path, $vendor, $isDefault);
            $this->workspace->saveWorkspaceManifest($path);
        } catch (WorkspaceException $e) {
            return $this->handleWorkspaceException($e);
        }

        $defaultMsg = $this->workspace->getDefault() === $path ? ' (set as default)' : '';
        $vendorMsg = $vendor ? " with default vendor [{$vendor}] (flat structure)" : ' (multi-vendor nested structure)';
        $this->info("Workspace [{$path}] registered successfully{$defaultMsg}{$vendorMsg}.");

        $packages = $this->workspace->all()[$path]['packages'] ?? [];
        $packageCount = count($packages);
        if ($packageCount > 0) {
            $this->line("  <info>Discovered and registered {$packageCount} existing package(s) from disk.</info>");
        }

        // Check for Git-limbo hazard: directories without composer.json or .git
        $unversioned = $this->workspace->detectUnversionedDirectories($path);
        if (! empty($unversioned)) {
            $this->dispatchDiagnostic(
                code: 'WS_UNVERSIONED_DIRECTORIES_DETECTED',
                message: "Workspace directory [{$path}] contains subdirectories that are NOT packages and lack Git repositories:\n  • ".implode("\n  • ", $unversioned)."\n\nBecause the entire workspace path [/{$path}] is excluded by root .gitignore, files inside these directories will NEVER be tracked or committed to Git!",
                severity: DiagnosticSeverity::Warning,
                context: [
                    'Unversioned directories' => $unversioned,
                    '.gitignore exclusion' => "/{$path}",
                ],
                remediationSteps: [
                    "Convert into isolated packages: php artisan package:make <name> --workspace={$path}",
                    "Or move them outside [{$path}] into a tracked application directory (e.g. app/)",
                ],
                agentGuidance: 'Unversioned directories in a gitignored workspace will not be tracked by Git. Move them outside or make them proper packages.'
            );
        }

        $this->newLine();
        $this->line('  <comment>Proactive hint:</comment> You can now create packages in this workspace:');
        if ($vendor) {
            $this->line("  <info>php artisan package:make my-package --workspace={$path}</info>");
            $this->line('  In flat workspaces, module directories commonly use TitleCase aliases (e.g. Billing):');
            $this->line("  <info>php artisan package:make my-package --workspace={$path} --alias=MyPackage</info>");
        } else {
            $this->line("  <info>php artisan package:make my-vendor/my-package --workspace={$path}</info>");
        }

        return self::SUCCESS;
    }
}
