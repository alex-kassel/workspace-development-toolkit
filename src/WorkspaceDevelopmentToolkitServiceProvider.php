<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit;

use AlexKassel\WorkspaceDevelopmentToolkit\Commands\PackageAliasCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\PackageCheckCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\PackageDeleteCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\PackageInstallCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\PackageMakeCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\PackageReadmeCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\PackageReleaseCheckCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\PackageSkillsCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\PackageUninstallCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\WorkspaceAddCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\WorkspaceCloneCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\WorkspaceDefaultCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\WorkspaceHelpCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\WorkspaceListCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\WorkspaceRemoveCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerManager;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\FilesystemHelper;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\GitInspector;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\IsolatedPackageVerifier;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ManifestRepository;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\PackageResolver;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\PackageScaffolder;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\PackageVerifier;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ReadmeValidator;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ReleaseChecker;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\SkillInstaller;
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
        $this->app->singleton(IsolatedPackageVerifier::class);
        $this->app->singleton(GitInspector::class);
        $this->app->singleton(ReadmeValidator::class);
        $this->app->singleton(ReleaseChecker::class);
        $this->app->singleton(SkillInstaller::class, function () {
            return new SkillInstaller;
        });
        $this->app->singleton(PackageScaffolder::class);
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

            /** @var SkillInstaller $installer */
            $installer = $this->app->make(SkillInstaller::class);
            $skillsSource = $installer->getDefaultSourcePath();
            $discoveredSkills = $installer->discoverSkillsInPath($skillsSource);
            $skillsPublishMap = [];
            $targetSkillsBase = $installer->detectSkillsDirectory();

            foreach ($discoveredSkills as $slug => $sourceDir) {
                $skillMd = $sourceDir.DIRECTORY_SEPARATOR.'SKILL.md';
                if ($installer->isPublished($skillMd)) {
                    $skillsPublishMap[$sourceDir] = $targetSkillsBase.DIRECTORY_SEPARATOR.$slug;
                }
            }

            if (! empty($skillsPublishMap)) {
                $this->publishes($skillsPublishMap, 'workspace-skills');
            }

            $this->commands([
                PackageMakeCommand::class,
                PackageCheckCommand::class,
                PackageInstallCommand::class,
                PackageUninstallCommand::class,
                PackageDeleteCommand::class,
                PackageAliasCommand::class,
                PackageReadmeCommand::class,
                PackageReleaseCheckCommand::class,
                PackageSkillsCommand::class,
                WorkspaceAddCommand::class,
                WorkspaceCloneCommand::class,
                WorkspaceDefaultCommand::class,
                WorkspaceListCommand::class,
                WorkspaceRemoveCommand::class,
                WorkspaceHelpCommand::class,
            ]);

            $this->autoPublishSkill();
        }
    }

    /**
     * Automatically materialize published skills into the project during local development discovery.
     */
    protected function autoPublishSkill(): void
    {
        if ($this->app->environment('production')) {
            return;
        }

        if (! config('workspace.auto_publish_skill', true)) {
            return;
        }

        /** @var SkillInstaller $installer */
        $installer = $this->app->make(SkillInstaller::class);
        $skillsSource = $installer->getDefaultSourcePath();

        $discovered = $installer->discoverSkillsInPath($skillsSource);
        foreach ($discovered as $slug => $sourceDir) {
            $skillMd = $sourceDir.DIRECTORY_SEPARATOR.'SKILL.md';
            if ($installer->isPublished($skillMd) && ! $installer->isInstalled($slug, $sourceDir)) {
                $installer->installSkill($slug, $sourceDir);
            }
        }
    }
}
