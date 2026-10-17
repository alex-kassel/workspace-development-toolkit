<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\GitOperationResult;
use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\GitRepositoryState;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerManager;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceGitService;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceManager;

class WorkspacePushCommand extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workspace:push
        {--workspace= : Filter packages by a specific workspace}
        {--json : Output a machine-readable JSON report}
        {--no-host : Exclude the host application repository}
        {--dry-run : Report what would be pushed without pushing}
        {--no-tags : Do not push tags}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Push all clean workspace repositories (and the host application) to their remotes';

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

        $includeHost = ! (bool) $this->option('no-host');
        $states = $this->git->collect($workspaceFilter, $includeHost, false);

        if ($states === []) {
            $this->warn('No repositories to push.');

            return self::SUCCESS;
        }

        // Push packages first so the host repository only records references to pushed commits.
        usort($states, fn (GitRepositoryState $a, GitRepositoryState $b): int => (int) $a->isHost <=> (int) $b->isHost);

        $dryRun = (bool) $this->option('dry-run');
        $followTags = ! (bool) $this->option('no-tags');

        $results = [];
        foreach ($states as $state) {
            if ($state->isHost && ! $state->isRepository) {
                $results[] = new GitOperationResult(
                    repository: $state->name,
                    action: 'push',
                    success: true,
                    message: 'Skipped: host is not a git repository',
                );

                continue;
            }

            $results[] = $this->git->push($state, $followTags, $dryRun);
        }

        $failures = array_values(array_filter($results, fn (GitOperationResult $result): bool => ! $result->success));
        $pushed = array_values(array_filter($results, fn (GitOperationResult $result): bool => $result->success && $result->changed));

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'results' => array_map(fn (GitOperationResult $result): array => $result->toArray(), $results),
                'summary' => [
                    'total' => count($results),
                    'pushed' => count($pushed),
                    'failed' => count($failures),
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $failures === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->info($dryRun ? 'Workspace Git Push (dry-run)' : 'Workspace Git Push');
        $this->newLine();

        $rows = [];
        foreach ($results as $result) {
            $rows[] = [
                $result->repository,
                $result->changed ? ($dryRun ? 'Would push' : 'Pushed') : 'Up to date',
                $result->success ? '<info>OK</info>' : '<error>FAILED</error>',
                $result->message,
            ];
        }

        $this->table(['Repository', 'Action', 'Result', 'Details'], $rows);
        $this->newLine();

        $this->line(sprintf(
            '  <info>Summary:</info> %d repository/repositories | <info>%d %s</info> | %s',
            count($results),
            count($pushed),
            $dryRun ? 'would be pushed' : 'pushed',
            $failures === []
                ? '<info>everything is synchronized</info>'
                : '<error>'.count($failures).' failed</error>',
        ));

        foreach ($results as $result) {
            if ($result->success) {
                continue;
            }

            $this->line("  <error>✖</error> {$result->repository}: {$result->message}");
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }
}
