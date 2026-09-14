<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\WorkspaceContext;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerManager;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceInstaller;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceManager;

class WorkspaceInstallCommand extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workspace:install
        {workspace? : Default workspace directory name (e.g. packages)}
        {--self : Link guidelines to local development package repository}
        {--package-path= : Relative path to the local cloned package (used with --self)}
        {--force : Force overwrite existing configuration files}
        {--skip-cleanup : Skip cleaning up redundant host artifacts (e.g. CLOUD.md)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialize workspace development environment, guidelines, runner and configuration';

    public function __construct(
        WorkspaceManager $workspace,
        ComposerManager $composer,
        protected readonly WorkspaceInstaller $installer,
    ) {
        parent::__construct($workspace, $composer);
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $rawWorkspace = (string) $this->argument('workspace');
        $defaultWorkspace = trim($rawWorkspace) !== '' ? trim($rawWorkspace) : 'packages';
        $isSelf = (bool) $this->option('self');
        $packagePath = $this->option('package-path') ? (string) $this->option('package-path') : null;

        if ($isSelf && $packagePath === null) {
            $packagePath = 'packages/alex-kassel/workspace-development-toolkit';
        }

        $force = (bool) $this->option('force');
        $skipCleanup = (bool) $this->option('skip-cleanup');

        $context = new \AlexKassel\WorkspaceDevelopmentToolkit\DTOs\WorkspaceContext(
            rootPath: base_path(),
            defaultWorkspace: $defaultWorkspace,
            isSelf: $isSelf,
            selfPackagePath: $packagePath,
            force: $force,
            skipCleanup: $skipCleanup,
        );

        $this->info('Initializing Workspace Development Toolkit...');
        $result = $this->installer->install($context);

        $hasFailure = false;
        foreach ($result->steps as $step) {
            $statusIcon = match ($step['status']) {
                'created', 'cleaned', 'updated' => '<info>✔</info>',
                'linked' => '<info>🔗</info>',
                'skipped' => '<comment>⏭</comment>',
                'failed' => '<error>✖</error>',
                default => '•',
            };

            if ($step['status'] === 'failed') {
                $hasFailure = true;
            }

            $processorName = $step['processor'] ?? $step['action'] ?? 'step';
            $this->line("  {$statusIcon} [{$processorName}] {$step['message']}");
        }

        if ($hasFailure) {
            $this->newLine();
            $this->warn('Workspace installation completed with warnings/failures.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('✔ Workspace environment initialized successfully!');
        $this->line('  <comment>Next steps:</comment>');
        $this->line("  • Create a package: <info>php artisan package:make <vendor/package> --workspace={$defaultWorkspace}</info>");
        $this->line('  • Check workspace status: <info>php artisan workspace:status</info>');

        return self::SUCCESS;
    }
}
