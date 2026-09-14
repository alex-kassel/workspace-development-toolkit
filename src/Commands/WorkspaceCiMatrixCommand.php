<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Services\CiMatrixGenerator;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerManager;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\FilesystemHelper;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceManager;
use Illuminate\Support\Facades\File;

class WorkspaceCiMatrixCommand extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workspace:ci-matrix
        {--json : Output raw JSON matrix for GitHub Actions dynamic matrix strategy}
        {--file= : Generate workflow file at specified path (default: .github/workflows/packages-matrix.yml)}
        {--only-changed : Only include packages with changes against base branch}
        {--base=origin/main : Base branch for change detection}
        {--dry-run : Print generated workflow YAML to console without writing to disk}
        {--force : Overwrite existing workflow file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a GitHub Actions CI test matrix workflow for all workspace packages';

    public function __construct(
        WorkspaceManager $workspace,
        ComposerManager $composer,
        protected readonly CiMatrixGenerator $matrixGenerator,
    ) {
        parent::__construct($workspace, $composer);
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $asJson = (bool) $this->option('json');
        $onlyChanged = (bool) $this->option('only-changed');
        $base = (string) ($this->option('base') ?: 'origin/main');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $customFile = $this->option('file');

        $packagesFilter = [];
        if ($onlyChanged) {
            $packagesFilter = $this->matrixGenerator->detectChangedPackages($base);
        }

        if ($asJson) {
            $matrix = $this->matrixGenerator->generateWorkspaceMatrix($packagesFilter);
            $json = json_encode($matrix, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            foreach (explode("\n", (string) $json) as $jsonLine) {
                $this->line($jsonLine);
            }

            return self::SUCCESS;
        }

        $yaml = $this->matrixGenerator->generateWorkspaceWorkflowYaml($packagesFilter);

        if ($dryRun) {
            foreach (explode("\n", str_replace("\r", '', $yaml)) as $yamlLine) {
                $this->line($yamlLine);
            }

            return self::SUCCESS;
        }

        $targetRelative = is_string($customFile) && trim($customFile) !== ''
            ? trim($customFile)
            : '.github/workflows/packages-matrix.yml';

        $targetPath = base_path($targetRelative);
        $targetCanonical = FilesystemHelper::canonicalPath($targetPath);
        $baseCanonical = FilesystemHelper::canonicalPath(base_path());

        if (! str_starts_with($targetCanonical, $baseCanonical.'/')) {
            $this->error("Security violation: Workflow file path [{$targetRelative}] resolves outside the application root.");

            return self::FAILURE;
        }

        $targetDir = dirname($targetPath);

        if (File::exists($targetPath) && ! $force) {
            $this->warn("GitHub Actions workflow already exists at: {$targetRelative}");
            $this->line('  <comment>How to fix:</comment> Use the [--force] flag to overwrite:');
            $this->line('  <info>php artisan workspace:ci-matrix --force</info>');

            return self::SUCCESS;
        }

        File::ensureDirectoryExists($targetDir);
        File::put($targetPath, $yaml);

        $this->info('✔ GitHub Actions test matrix generated for workspace:');
        $this->line("  <comment>Location:</comment> {$targetRelative}");

        return self::SUCCESS;
    }
}
