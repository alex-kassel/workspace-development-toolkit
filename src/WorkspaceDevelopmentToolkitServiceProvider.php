<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit;

use AlexKassel\WorkspaceDevelopmentToolkit\Commands\PackageAliasCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\PackageCheckCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\PackageDeleteCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\PackageInstallCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\PackageMakeCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\PackageUninstallCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\WorkspaceAddCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\WorkspaceCloneCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\WorkspaceDefaultCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\WorkspaceHelpCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\WorkspaceListCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\WorkspaceRemoveCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerManager;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\FilesystemHelper;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ManifestRepository;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\PackageResolver;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\PackageVerifier;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceManager;
use Illuminate\Support\ServiceProvider;

class WorkspaceDevelopmentToolkitServiceProvider extends ServiceProvider
{
    /**
     * Register any package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/workspace.php', 'workspace');

        $this->app->singleton(ManifestRepository::class);
        $this->app->singleton(FilesystemHelper::class);
        $this->app->singleton(ComposerManager::class);
        $this->app->singleton(PackageResolver::class);
        $this->app->singleton(WorkspaceManager::class);
        $this->app->singleton(PackageVerifier::class);
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/workspace.php' => config_path('workspace.php'),
            ], 'workspace-config');

            $this->publishes([
                __DIR__.'/../stubs/package' => base_path('stubs/workspace'),
            ], 'workspace-stubs');

            $this->commands([
                PackageMakeCommand::class,
                PackageCheckCommand::class,
                PackageInstallCommand::class,
                PackageUninstallCommand::class,
                PackageDeleteCommand::class,
                PackageAliasCommand::class,
                WorkspaceAddCommand::class,
                WorkspaceCloneCommand::class,
                WorkspaceDefaultCommand::class,
                WorkspaceListCommand::class,
                WorkspaceRemoveCommand::class,
                WorkspaceHelpCommand::class,
            ]);
        }
    }
}
