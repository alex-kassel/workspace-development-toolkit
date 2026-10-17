<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\GitRepositoryState;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerManager;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceGitService;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceManager;

class WorkspaceCheckCommand extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workspace:check
        {--workspace= : Filter packages by a specific workspace}
        {--json : Output a machine-readable JSON report}
        {--no-host : Exclude the host application repository}
        {--fetch : Fetch remotes before inspecting local state}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify that the host application and all workspace packages are clean and pushed';

    public function __construct(
        WorkspaceManager $workspace,
        ComposerManager $composer,
        protected readonly WorkspaceGitService $git,
    ) {
        parent::__construct($workspace, $composer);
    }

    public function handle(): int
    {
        $workspaceFilter = $this->option('workspace') ? (string) $this->option('workspace') : null;

        if ($workspaceFilter !== null && ! isset($this->workspace->all()[$workspaceFilter])) {
            $this->error("Workspace [{$workspaceFilter}] not found.");

            return self::FAILURE;
        }

        $states = $this->git->collect(
            workspaceFilter: $workspaceFilter,
            includeHost: ! (bool) $this->option('no-host'),
            fetch: (bool) $this->option('fetch'),
        );

        if ($states === []) {
            $this->warn('No repositories to check.');

            return self::SUCCESS;
        }

        $withErrors = array_values(array_filter($states, fn (GitRepositoryState $state): bool => $state->hasErrors()));
        $withWarnings = array_values(array_filter(
            $states,
            fn (GitRepositoryState $state): bool => ! $state->hasErrors() && $state->warnings() !== []
        ));

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'repositories' => array_map(fn (GitRepositoryState $state): array => $state->toArray(), $states),
                'summary' => [
                    'total' => count($states),
                    'clean' => count($states) - count($withErrors),
                    'errors' => count($withErrors),
                    'warnings' => count($withWarnings),
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $withErrors === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Workspace Git Preflight');
        $this->newLine();

        $rows = [];
        foreach ($states as $state) {
            $rows[] = [
                $state->isHost ? "<options=bold>{$state->name} (host)</>" : $state->name,
                $state->branch ?? '-',
                $this->formatTree($state),
                $this->formatUpstream($state),
                $state->unpushedTags === [] ? '-' : (string) count($state->unpushedTags),
                $this->formatIssues($state),
            ];
        }

        $this->table(['Repository', 'Branch', 'Tree', 'Upstream', 'Tags', 'Issues'], $rows);

        $this->newLine();

        foreach ($states as $state) {
            foreach ($state->errors() as $error) {
                $this->line("  <error>✖</error> {$state->name}: {$error}");
            }

            foreach ($state->warnings() as $warning) {
                $this->line("  <comment>⚠</comment> {$state->name}: {$warning}");
            }
        }

        $clean = count($states) - count($withErrors);

        $this->line(sprintf(
            '  <info>Summary:</info> %d repository/repositories checked | <info>%d clean</info> | %s | %s',
            count($states),
            $clean,
            count($withErrors) > 0 ? "<error>{$this->countIssues($withErrors)} error(s) in ".count($withErrors).' repository/repositories</error>' : '<info>0 errors</info>',
            count($withWarnings) > 0 ? "<comment>{$this->countWarnings($withWarnings)} warning(s) in ".count($withWarnings).' repository/repositories</comment>' : '<info>0 warnings</info>',
        ));

        return $withErrors === [] ? self::SUCCESS : self::FAILURE;
    }

    protected function formatTree(GitRepositoryState $state): string
    {
        if (! $state->exists) {
            return '<error>Missing</error>';
        }

        if (! $state->isRepository) {
            return '<comment>No git repo</comment>';
        }

        if (! $state->isClean) {
            return "<error>Dirty ({$state->dirtyFilesCount})</error>";
        }

        return '<info>Clean</info>';
    }

    protected function formatUpstream(GitRepositoryState $state): string
    {
        if (! $state->exists || ! $state->isRepository) {
            return '-';
        }

        return match (true) {
            $state->isDetached => '<error>Detached</error>',
            $state->syncStatus === 'Synced' => '<info>Synced</info>',
            $state->syncStatus === 'Ahead' => "<comment>Ahead ↑{$state->ahead}</comment>",
            $state->syncStatus === 'Behind' => "<comment>Behind ↓{$state->behind}</comment>",
            $state->syncStatus === 'Diverged' => "<error>Diverged ↑{$state->ahead} ↓{$state->behind}</error>",
            $state->syncStatus === 'Unpublished' => "<error>Unpublished ↑{$state->ahead}</error>",
            default => "<comment>{$state->syncStatus}</comment>",
        };
    }

    protected function formatIssues(GitRepositoryState $state): string
    {
        $errors = $state->errors();
        if ($errors !== []) {
            return '<error>'.count($errors).' error(s)</error>';
        }

        $warnings = $state->warnings();
        if ($warnings !== []) {
            return '<comment>'.count($warnings).' warning(s)</comment>';
        }

        return '<info>OK</info>';
    }

    /**
     * @param  array<int, GitRepositoryState>  $states
     */
    protected function countIssues(array $states): int
    {
        return array_sum(array_map(fn (GitRepositoryState $state): int => count($state->errors()), $states));
    }

    /**
     * @param  array<int, GitRepositoryState>  $states
     */
    protected function countWarnings(array $states): int
    {
        return array_sum(array_map(fn (GitRepositoryState $state): int => count($state->warnings()), $states));
    }
}
