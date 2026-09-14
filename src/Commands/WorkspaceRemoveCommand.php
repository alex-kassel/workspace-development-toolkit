<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceNotFoundException;
use Laravel\Prompts\Prompt;

class WorkspaceRemoveCommand extends BaseWorkspaceCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workspace:remove
        {path? : The workspace directory path}
        {--detach : Uninstall active workspace packages from root composer.json before removing workspace}
        {--purge : Uninstall active packages and permanently delete workspace directory from disk}
        {--force : Bypass confirmation prompts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove a workspace from composer.json and workspace.json (directory is preserved by default)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $path = $this->getRequiredWorkspacePath();
        if ($path === null) {
            return self::FAILURE;
        }

        if (! array_key_exists($path, $this->workspace->all())) {
            $exception = new WorkspaceNotFoundException($path, array_keys($this->workspace->all()));

            if ($this->input->isInteractive() && @stream_isatty(STDIN) && ! empty($exception->available)) {
                try {
                    $usePrompt = class_exists(Prompt::class);
                    $confirm = $usePrompt
                        ? \Laravel\Prompts\confirm("Workspace [{$path}] is not registered. Choose from available workspaces instead?", true)
                        : $this->confirm("Workspace [{$path}] is not registered. Choose from available workspaces instead?", true);

                    if ($confirm) {
                        $selected = $usePrompt
                            ? \Laravel\Prompts\select('Select workspace to remove:', $exception->available)
                            : $this->choice('Select workspace to remove:', $exception->available, 0);

                        $path = (string) $selected;
                    } else {
                        return $this->handleWorkspaceException($exception);
                    }
                } catch (\Throwable) {
                    return $this->handleWorkspaceException($exception);
                }
            } else {
                return $this->handleWorkspaceException($exception);
            }
        }

        $activePackages = $this->workspace->getActivePackagesInWorkspace($path);
        $detach = (bool) $this->option('detach');
        $purge = (bool) $this->option('purge');
        $force = (bool) $this->option('force');
        $isInteractive = $this->input->isInteractive() && @stream_isatty(STDIN);

        if (! empty($activePackages) && ! $detach && ! $purge) {
            if ($isInteractive) {
                try {
                    $usePrompt = class_exists(Prompt::class);

                    $this->newLine();
                    $this->warn("Workspace [{$path}] contains active packages installed in root composer.json:");
                    foreach ($activePackages as $pkg => $reqType) {
                        $this->line("  <fg=cyan>•</> <info>{$pkg}</info> <fg=gray>({$reqType})</>");
                    }
                    $this->newLine();

                    $choice = $usePrompt
                        ? \Laravel\Prompts\select(
                            label: "How would you like to handle active packages in workspace [{$path}]?",
                            options: [
                                'cancel' => 'Cancel (safe default)',
                                'detach' => 'Detach & Uninstall (uninstall from composer.json, remove workspace, keep files on disk)',
                                'purge' => 'Purge (uninstall from composer.json, remove workspace, permanently delete files from disk)',
                            ],
                            default: 'cancel'
                        )
                        : $this->choice(
                            "How would you like to handle active packages in workspace [{$path}]?",
                            [
                                'cancel' => 'Cancel (safe default)',
                                'detach' => 'Detach & Uninstall (uninstall from composer.json, remove workspace, keep files on disk)',
                                'purge' => 'Purge (uninstall from composer.json, remove workspace, permanently delete files from disk)',
                            ],
                            'cancel'
                        );

                    if ($choice === 'cancel') {
                        $this->info('Workspace removal canceled.');

                        return self::SUCCESS;
                    }

                    if ($choice === 'detach') {
                        $detach = true;
                    } elseif ($choice === 'purge') {
                        $purge = true;
                    }
                } catch (\Throwable $e) {
                    if (str_contains(get_class($e), 'PromptCancelled') || str_contains($e->getMessage(), 'cancel')) {
                        $this->info('Workspace removal canceled.');

                        return self::SUCCESS;
                    }

                    $detach = false;
                    $purge = false;
                }
            }

            if (! $detach && ! $purge) {
                $packageLines = [];
                foreach ($activePackages as $pkg => $reqType) {
                    $packageLines[] = "• {$pkg} ({$reqType})";
                }

                $this->dispatchDiagnostic(
                    code: 'WS_WORKSPACE_CONTAINS_ACTIVE_PACKAGES',
                    message: "Cannot remove workspace [{$path}]: workspace contains active packages required by root composer.json.\n\nRemoving the workspace path repository without uninstalling them will corrupt Composer autoloading and dependency resolution.",
                    context: $packageLines,
                    remediationSteps: [
                        'To safely detach and uninstall these packages before removing the workspace, run:',
                        "  php artisan workspace:remove {$path} --detach",
                        'To completely purge the workspace and its files from disk, run:',
                        "  php artisan workspace:remove {$path} --purge",
                        'Or manually uninstall the active packages first:',
                        ...array_map(fn ($pkg) => "  php artisan package:uninstall {$pkg}", array_keys($activePackages)),
                    ],
                    agentGuidance: 'Do not remove a workspace containing active dependencies. Re-run with --detach to automatically uninstall the packages from composer.json while preserving physical files, or uninstall active packages first.'
                );

                return self::FAILURE;
            }
        }

        if ($purge) {
            if (! $force && $isInteractive) {
                $usePrompt = class_exists(Prompt::class);
                $confirm = $usePrompt
                    ? \Laravel\Prompts\confirm("Are you SURE you want to permanently delete workspace [{$path}] and ALL its files from disk?", false)
                    : $this->confirm("Are you SURE you want to permanently delete workspace [{$path}] and ALL its files from disk?", false);

                if (! $confirm) {
                    $this->info('Purge canceled.');

                    return self::SUCCESS;
                }
            }

            try {
                $this->workspace->purge($path, force: $force);
            } catch (WorkspaceException $e) {
                return $this->handleWorkspaceException($e);
            }

            $this->info("Workspace [{$path}] and all contained files permanently purged from disk and configuration.");

            return self::SUCCESS;
        }

        try {
            if ($detach) {
                $this->workspace->detach($path);
            } else {
                $this->workspace->remove($path, force: true);
            }
        } catch (WorkspaceException $e) {
            return $this->handleWorkspaceException($e);
        }

        $this->info("Workspace [{$path}] removed from configuration (composer.json and workspace.json).");

        $this->newLine();
        $this->line('  <comment>Hint:</comment> Workspace removal only unregisters the repository.');
        $this->line("  1. The physical directory [{$path}/] was preserved.");
        $this->line("     <fg=yellow;options=bold>CAUTION:</> Before deleting it manually, verify that git working trees inside [{$path}/] are clean and all commits have been pushed!");
        $this->line("  2. The ignore rule [/{$path}] has been cleanly removed from your .gitignore file.");
        if ($detach && ! empty($activePackages)) {
            $this->line('  3. Active packages were uninstalled from root composer.json.');
        }

        return self::SUCCESS;
    }
}
