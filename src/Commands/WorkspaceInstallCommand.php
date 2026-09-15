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
        {--default= : Explicitly specify which initial workspace should be default}
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
        $configWorkspaces = config('workspace.initial_workspaces');
        if (is_string($configWorkspaces) && trim($configWorkspaces) !== '') {
            $workspaces = array_values(array_filter(array_map('trim', explode(',', $configWorkspaces))));
        } elseif (is_array($configWorkspaces)) {
            $workspaces = array_values(array_filter(array_map('trim', $configWorkspaces)));
        } else {
            $workspaces = [];
        }

        $defaultOption = $this->option('default');
        $defaultWorkspace = is_string($defaultOption) && trim($defaultOption) !== ''
            ? trim($defaultOption)
            : ($workspaces[0] ?? null);

        if ($defaultWorkspace !== null && ! in_array($defaultWorkspace, $workspaces, true)) {
            array_unshift($workspaces, $defaultWorkspace);
        }

        $isSelf = (bool) $this->option('self');
        $packagePath = $this->option('package-path') ? (string) $this->option('package-path') : null;

        if ($isSelf && $packagePath === null) {
            $packagePath = 'packages/alex-kassel/workspace-development-toolkit';
        }

        $force = (bool) $this->option('force');
        $skipCleanup = (bool) $this->option('skip-cleanup');

        $context = new WorkspaceContext(
            rootPath: base_path(),
            workspaces: $workspaces,
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

            $this->line("  {$statusIcon} [{$step['processor']}] {$step['message']}");
        }

        if ($hasFailure) {
            $this->newLine();
            $this->warn('Workspace installation completed with warnings/failures.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('✔ Workspace environment initialized successfully!');
        $this->line('  <comment>Next steps:</comment>');
        if ($defaultWorkspace !== null) {
            $this->line("  • Create a package: <info>php artisan package:make <vendor/package> --workspace={$defaultWorkspace}</info>");
        } else {
            $this->line('  • Register a workspace: <info>php artisan workspace:register <name></info>');
        }
        $this->line('  • Check workspace status: <info>php artisan workspace:status</info>');

        return self::SUCCESS;
    }
}
