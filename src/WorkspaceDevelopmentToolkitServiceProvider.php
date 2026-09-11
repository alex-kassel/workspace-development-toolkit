<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit;

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

        $this->app->singleton(WorkspaceManager::class, fn () => new WorkspaceManager);
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

            $this->commands([
                PackageMakeCommand::class,
                PackageInstallCommand::class,
                PackageUninstallCommand::class,
                PackageDeleteCommand::class,
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
