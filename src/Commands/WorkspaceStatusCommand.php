<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerManager;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceManager;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceStatusCollector;

class WorkspaceStatusCommand extends BaseWorkspaceCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workspace:status
        {--workspace= : Filter status by specific workspace}
        {--dirty : Show only packages with uncommitted changes, unpushed commits, or issues}
        {--json : Output report in machine-readable JSON format}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display interactive status dashboard of all packages across workspaces';

    public function __construct(
        WorkspaceManager $workspace,
        ComposerManager $composer,
        protected WorkspaceStatusCollector $statusCollector,
    ) {
        parent::__construct($workspace, $composer);
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $manifest = $this->workspace->load();
        } catch (WorkspaceException $e) {
            return $this->handleWorkspaceException($e);
        }

        $workspaces = $manifest['workspaces'];

        if (empty($workspaces)) {
            if ($this->option('json')) {
                $this->line((string) json_encode([], JSON_PRETTY_PRINT));

                return self::SUCCESS;
            }

            $this->info('No workspaces registered.');
            $this->line('  <comment>How to fix:</comment> Register a workspace using:');
            $this->line('  <info>php artisan workspace:add packages</info>');

            return self::SUCCESS;
        }

        $workspaceFilter = $this->option('workspace') ? (string) $this->option('workspace') : null;
        if ($workspaceFilter !== null && ! isset($workspaces[$workspaceFilter])) {
            $this->error("Workspace [{$workspaceFilter}] not found.");
            $this->line('  <comment>How to fix:</comment> View registered workspaces using:');
            $this->line('  <info>php artisan workspace:list</info>');

            return self::FAILURE;
        }

        $onlyDirty = (bool) $this->option('dirty');
        $statuses = $this->statusCollector->collect($workspaceFilter, $onlyDirty);

        if ($this->option('json')) {
            $data = array_map(fn ($s) => $s->toArray(), $statuses);
            $this->line((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if (empty($statuses)) {
            if ($onlyDirty) {
                $this->info('All workspace packages are clean and synchronized!');
            } else {
                $this->info('No packages found in registered workspaces.');
            }

            return self::SUCCESS;
        }

        $this->info('Workspace Status Dashboard');
        $this->newLine();

        $rows = [];
        $cleanCount = 0;
        $issueCount = 0;

        foreach ($statuses as $status) {
            if ($status->hasIssues()) {
                $issueCount++;
            } else {
                $cleanCount++;
            }

            $rows[] = [
                $status->packageName,
                $status->workspace,
                $status->branch ?? '<comment>(detached / none)</comment>',
                $status->gitStatus === 'Clean'
                    ? '<info>Clean</info>'
                    : ($status->gitStatus === 'No git repo' ? '<comment>No git repo</comment>' : "<comment>{$status->gitStatus}</comment>"),
                $this->formatUpstream($status->upstream),
                str_starts_with($status->installStatus, 'Symlinked')
                    ? "<info>{$status->installStatus}</info>"
                    : (str_starts_with($status->installStatus, 'Installed') ? "<comment>{$status->installStatus}</comment>" : "<error>{$status->installStatus}</error>"),
                $this->formatAudit($status->auditStatus),
            ];
        }

        $this->table(['Package', 'Workspace', 'Branch', 'Git Status', 'Upstream', 'Installed', 'Audit'], $rows);

        $this->newLine();
        $total = count($statuses);
        $this->line("  <info>Summary:</info> {$total} package(s) inspected | <info>{$cleanCount} healthy</info> | ".($issueCount > 0 ? "<comment>{$issueCount} with notices/issues</comment>" : '<info>0 issues</info>'));

        return self::SUCCESS;
    }

    protected function formatUpstream(string $upstream): string
    {
        if ($upstream === 'Synced') {
            return '<info>Synced</info>';
        }

        if (str_starts_with($upstream, 'Ahead')) {
            return "<comment>{$upstream}</comment>";
        }

        if (str_starts_with($upstream, 'Behind') || str_starts_with($upstream, 'Diverged')) {
            return "<error>{$upstream}</error>";
        }

        return "<comment>{$upstream}</comment>";
    }

    protected function formatAudit(string $audit): string
    {
        if ($audit === 'Verified') {
            return '<info>Verified</info>';
        }

        if ($audit === 'Uncertified') {
            return '<comment>Uncertified</comment>';
        }

        return "<error>{$audit}</error>";
    }
}
