<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit;

use AlexKassel\WorkspaceDevelopmentToolkit\Checks\ComposerValidateCheck;
use AlexKassel\WorkspaceDevelopmentToolkit\Checks\ExportIgnoreCheck;
use AlexKassel\WorkspaceDevelopmentToolkit\Checks\GitCleanlinessCheck;
use AlexKassel\WorkspaceDevelopmentToolkit\Checks\IsolatedInstallCheck;
use AlexKassel\WorkspaceDevelopmentToolkit\Checks\PhpstanCheck;
use AlexKassel\WorkspaceDevelopmentToolkit\Checks\PintStyleCheck;
use AlexKassel\WorkspaceDevelopmentToolkit\Checks\ReadmeComplianceCheck;
use AlexKassel\WorkspaceDevelopmentToolkit\Checks\TestSuiteCheck;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\PackageAliasCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\PackageAuditCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\PackageCheckCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\PackageCloneCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\PackageDeleteCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\PackageDepsCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\PackageInstallCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\PackageMakeCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\PackageReadmeCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\PackageReleaseCheckCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\PackageSkillsCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\PackageUninstallCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\PackageWorkflowCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\WorkspaceAddCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\WorkspaceArchetypesCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\WorkspaceCiMatrixCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\WorkspaceDashboardCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\WorkspaceDefaultCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\WorkspaceHelpCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\WorkspaceListCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\WorkspaceRemoveCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\WorkspaceStatusCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\WorkspaceStubsCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Commands\WorkspaceSyncCommand;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\BinaryResolver;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\CertificateVerifier;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\CiMatrixGenerator;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerDiagnosticService;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerManager;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\FilesystemHelper;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\FingerprintCalculator;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\GitDiagnosticService;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\GitInspector;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\IsolatedPackageVerifier;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ManifestRepository;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\PackageAuditor;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\PackageGraph;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\PackageResolver;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\PackageScaffolder;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\PackageVerifier;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ReadmeValidator;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ReleaseChecker;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\SkillInstaller;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\VerificationPipeline;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceManager;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceStatusCollector;
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
        $this->app->singleton(BinaryResolver::class);

        $this->app->singleton(ComposerValidateCheck::class);
        $this->app->singleton(PintStyleCheck::class);
        $this->app->singleton(PhpstanCheck::class);
        $this->app->singleton(TestSuiteCheck::class);
        $this->app->singleton(IsolatedInstallCheck::class);
        $this->app->singleton(GitCleanlinessCheck::class);
        $this->app->singleton(ReadmeComplianceCheck::class);
        $this->app->singleton(ExportIgnoreCheck::class);

        $this->app->singleton(VerificationPipeline::class, function ($app) {
            $pipeline = new VerificationPipeline;
            $pipeline->registerCheck($app->make(ComposerValidateCheck::class), ['composer_validate']);
            $pipeline->registerCheck($app->make(PintStyleCheck::class));
            $pipeline->registerCheck($app->make(PhpstanCheck::class));
            $pipeline->registerCheck($app->make(TestSuiteCheck::class));
            $pipeline->registerCheck($app->make(IsolatedInstallCheck::class));
            $pipeline->registerCheck($app->make(GitCleanlinessCheck::class));
            $pipeline->registerCheck($app->make(ReadmeComplianceCheck::class));
            $pipeline->registerCheck($app->make(ExportIgnoreCheck::class));

            return $pipeline;
        });

        $this->app->singleton(PackageVerifier::class);
        $this->app->singleton(IsolatedPackageVerifier::class);
        $this->app->singleton(GitInspector::class);
        $this->app->singleton(ReadmeValidator::class);
        $this->app->singleton(ReleaseChecker::class);
        $this->app->singleton(FingerprintCalculator::class);
        $this->app->singleton(PackageAuditor::class);
        $this->app->singleton(CertificateVerifier::class);
        $this->app->singleton(SkillInstaller::class, function () {
            return new SkillInstaller;
        });
        $this->app->singleton(PackageScaffolder::class);
        $this->app->singleton(GitDiagnosticService::class);
        $this->app->singleton(ComposerDiagnosticService::class);
        $this->app->singleton(PackageGraph::class);
        $this->app->singleton(CiMatrixGenerator::class);
        $this->app->singleton(WorkspaceStatusCollector::class);
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
                PackageAuditCommand::class,
                PackageInstallCommand::class,
                PackageUninstallCommand::class,
                PackageDeleteCommand::class,
                PackageAliasCommand::class,
                PackageReadmeCommand::class,
                PackageReleaseCheckCommand::class,
                PackageSkillsCommand::class,
                PackageCloneCommand::class,
                PackageDepsCommand::class,
                PackageWorkflowCommand::class,
                WorkspaceAddCommand::class,
                WorkspaceCiMatrixCommand::class,
                WorkspaceDefaultCommand::class,
                WorkspaceListCommand::class,
                WorkspaceStatusCommand::class,
                WorkspaceRemoveCommand::class,
                WorkspaceSyncCommand::class,
                WorkspaceHelpCommand::class,
                WorkspaceStubsCommand::class,
                WorkspaceArchetypesCommand::class,
                WorkspaceDashboardCommand::class,
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
