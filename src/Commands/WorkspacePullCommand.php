<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\GitOperationResult;
use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\GitRepositoryState;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerManager;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceGitService;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class WorkspacePullCommand extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workspace:pull
        {--workspace= : Filter packages by a specific workspace}
        {--json : Output a machine-readable JSON report}
        {--no-host : Exclude the host application repository}
        {--dry-run : Report what would change without updating anything}
        {--no-install : Do not run composer install}
        {--migrate : Run migrations even when no migration files changed}
        {--no-migrate : Do not run migrations}
        {--assets : Rebuild front-end assets even when lock files did not change}
        {--no-assets : Do not install or build front-end assets}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fast-forward the host application and all workspace packages to their latest remote state';

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
        $dryRun = (bool) $this->option('dry-run');

        $before = $this->git->collect($workspaceFilter, $includeHost, false);
        $beforeSnapshot = $this->snapshot($before);

        /** @var array<int, GitOperationResult> $results */
        $results = [];
        $steps = [];

        if ($includeHost) {
            $host = $this->git->hostState(true);

            if ($host->isRepository) {
                $results[] = $this->git->pull($host, $dryRun);
            } else {
                $steps[] = ['host', 'Skipped: host is not a git repository.'];
            }
        }

        $missing = array_values(array_filter($before, fn (GitRepositoryState $state): bool => ! $state->exists));
        if ($missing !== []) {
            if ($dryRun) {
                $steps[] = ['restore', 'Would clone '.count($missing).' missing package(s).'];
            } else {
                $restore = $this->git->restoreMissing();
                $steps[] = ['restore', $restore->message];

                if (! $restore->success) {
                    $results[] = $restore;
                }
            }
        }

        $fresh = $this->git->collect($workspaceFilter, $includeHost, true);
        foreach ($fresh as $state) {
            if ($state->isHost) {
                continue;
            }

            $results[] = $this->git->pull($state, $dryRun);
        }

        $afterSnapshot = $this->snapshot($this->git->collect($workspaceFilter, $includeHost, false));
        $changed = $this->changedKeys($beforeSnapshot, $afterSnapshot);

        if (! $dryRun) {
            $steps = array_merge($steps, $this->refreshEnvironment($changed, $dryRun));
        }

        $finalStates = $this->git->collect($workspaceFilter, $includeHost, false);
        $failures = array_values(array_filter($results, fn (GitOperationResult $result): bool => ! $result->success));
        $finalErrors = array_values(array_filter($finalStates, fn (GitRepositoryState $state): bool => $state->hasErrors()));

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'results' => array_map(fn (GitOperationResult $result): array => $result->toArray(), $results),
                'steps' => $steps,
                'repositories' => array_map(fn (GitRepositoryState $state): array => $state->toArray(), $finalStates),
                'summary' => [
                    'updated' => count(array_filter($results, fn (GitOperationResult $result): bool => $result->success && $result->changed)),
                    'failed' => count($failures),
                    'repositories_with_errors' => count($finalErrors),
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return ($failures === [] && $finalErrors === []) ? self::SUCCESS : self::FAILURE;
        }

        $this->info($dryRun ? 'Workspace Git Pull (dry-run)' : 'Workspace Git Pull');
        $this->newLine();

        $rows = [];
        foreach ($results as $result) {
            $rows[] = [
                $result->repository,
                $result->changed ? ($dryRun ? 'Would update' : 'Updated') : 'Up to date',
                $result->success ? '<info>OK</info>' : '<error>FAILED</error>',
                $result->message,
            ];
        }

        $this->table(['Repository', 'Action', 'Result', 'Details'], $rows);

        if ($steps !== []) {
            $this->newLine();
            $this->line('  <comment>Refresh steps:</comment>');
            foreach ($steps as [$step, $message]) {
                $this->line("  • <info>{$step}</info>: {$message}");
            }
        }

        $this->newLine();
        $this->line(sprintf(
            '  <info>Summary:</info> %d repository/repositories | <info>%d updated</info> | %s',
            count($results),
            count(array_filter($results, fn (GitOperationResult $result): bool => $result->success && $result->changed)),
            $failures === [] && $finalErrors === []
                ? '<info>all repositories are synchronized</info>'
                : '<error>'.count($failures).' failed, '.count($finalErrors).' still have issues</error>',
        ));

        foreach ($results as $result) {
            if (! $result->success) {
                $this->line("  <error>✖</error> {$result->repository}: {$result->message}");
            }
        }

        foreach ($finalStates as $state) {
            foreach ($state->errors() as $error) {
                $this->line("  <error>✖</error> {$state->name}: {$error}");
            }
        }

        return ($failures === [] && $finalErrors === []) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Refresh dependencies and assets when the pulled state requires it.
     *
     * @param  array<int, string>  $changed
     * @return array<int, array{0: string, 1: string}>
     */
    protected function refreshEnvironment(array $changed, bool $dryRun): array
    {
        $steps = [];

        $composerChanged = $this->hasChangedFile($changed, ['composer.json', 'composer.lock']);
        $assetsChanged = $this->hasChangedFile($changed, ['package.json', 'package-lock.json']);
        $migrationsChanged = $this->hasChangedPath($changed, 'database/migrations');

        $installNeeded = ! (bool) $this->option('no-install')
            && ($composerChanged || ! File::exists(base_path('vendor/autoload.php')));

        if ($installNeeded) {
            try {
                $this->workspace->runComposer(['install', '--no-interaction']);
                $steps[] = ['composer', 'Dependencies installed from composer.lock.'];
            } catch (\Throwable $e) {
                $steps[] = ['composer', 'Failed: '.$e->getMessage()];
            }
        }

        $migrate = ! (bool) $this->option('no-migrate')
            && ($migrationsChanged || (bool) $this->option('migrate'));

        if ($migrate && File::exists(base_path('artisan'))) {
            $result = Process::timeout((int) config('workspace.process_timeout', 300))
                ->path(base_path())
                ->run([PHP_BINARY, 'artisan', 'migrate', '--force', '--no-interaction']);

            $steps[] = ['migrate', $result->successful() ? 'Database migrations applied.' : 'Failed: '.$this->firstLine($result->errorOutput() ?: $result->output())];
        } elseif ($migrate) {
            $steps[] = ['migrate', 'Skipped: artisan binary was not found.'];
        }

        $assets = ! (bool) $this->option('no-assets')
            && File::exists(base_path('package.json'))
            && ($assetsChanged || (bool) $this->option('assets'));

        if ($assets) {
            $install = Process::timeout((int) config('workspace.process_timeout', 300))
                ->path(base_path())
                ->run(['npm', 'install', '--ignore-scripts']);
            $build = $install->successful()
                ? Process::timeout((int) config('workspace.process_timeout', 300))
                    ->path(base_path())
                    ->run(['npm', 'run', 'build'])
                : null;

            $steps[] = [
                'assets',
                $install->successful() && $build !== null && $build->successful()
                    ? 'Front-end dependencies installed and assets built.'
                    : 'Failed: '.$this->firstLine(($build?->errorOutput() ?: $install->errorOutput()) ?: $install->output()),
            ];
        }

        if (File::exists(base_path('artisan'))) {
            Process::timeout((int) config('workspace.process_timeout', 300))
                ->path(base_path())
                ->run([PHP_BINARY, 'artisan', 'optimize:clear']);
            $steps[] = ['cache', 'Application caches cleared.'];
        }

        return $steps;
    }

    /**
     * Snapshot files that influence dependency and asset installation.
     *
     * @param  array<int, GitRepositoryState>  $states
     * @return array<string, string>
     */
    protected function snapshot(array $states): array
    {
        $snapshot = [];

        foreach ($states as $state) {
            if (! $state->exists) {
                continue;
            }

            foreach (['composer.json', 'composer.lock', 'package.json', 'package-lock.json'] as $file) {
                $path = $state->path.DIRECTORY_SEPARATOR.$file;
                if (File::isFile($path)) {
                    $snapshot[$path] = (string) md5_file($path);
                }
            }

            $migrationsDir = $state->path.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';
            if (File::isDirectory($migrationsDir)) {
                $files = glob($migrationsDir.DIRECTORY_SEPARATOR.'*.php') ?: [];
                sort($files);
                $snapshot[$migrationsDir] = md5(implode('|', array_map(
                    fn (string $file): string => basename($file).':'.md5_file($file),
                    $files
                )));
            }
        }

        return $snapshot;
    }

    /**
     * @param  array<string, string>  $before
     * @param  array<string, string>  $after
     * @return array<int, string>
     */
    protected function changedKeys(array $before, array $after): array
    {
        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
        $changed = [];

        foreach ($keys as $key) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    /**
     * @param  array<int, string>  $changed
     * @param  array<int, string>  $filenames
     */
    protected function hasChangedFile(array $changed, array $filenames): bool
    {
        foreach ($changed as $path) {
            if (in_array(basename($path), $filenames, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $changed
     */
    protected function hasChangedPath(array $changed, string $segment): bool
    {
        return array_filter($changed, fn (string $path): bool => str_contains($path, $segment)) !== [];
    }

    protected function firstLine(string $output): string
    {
        $lines = array_filter(
            array_map('trim', explode("\n", str_replace("\r", '', $output))),
            fn (string $line): bool => $line !== ''
        );

        return $lines[0] ?? 'Unknown error';
    }
}
