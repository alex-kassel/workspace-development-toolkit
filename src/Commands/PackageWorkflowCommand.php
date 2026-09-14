<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\CiMatrixGenerator;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerManager;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceManager;
use Illuminate\Support\Facades\File;

class PackageWorkflowCommand extends BasePackageCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:workflow
        {package : Package name in vendor/package format (e.g. acme/my-pkg)}
        {--force : Overwrite existing workflow if present}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a GitHub Actions CI test matrix workflow for a package';

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
        $rawPackage = (string) $this->argument('package');
        $force = (bool) $this->option('force');

        try {
            $packagePath = $this->workspace->findPackagePath($rawPackage);
        } catch (WorkspaceException $e) {
            return $this->handleWorkspaceException($e);
        }

        if ($packagePath === null || ! File::isDirectory(base_path($packagePath))) {
            $this->error("Package [{$rawPackage}] not found.");
            $this->line('  <comment>How to fix:</comment> View registered packages using:');
            $this->line('  <info>php artisan workspace:list</info>');

            return self::FAILURE;
        }

        try {
            $package = $this->workspace->resolveCanonicalPackageName($rawPackage);
        } catch (WorkspaceException $e) {
            return $this->handleWorkspaceException($e);
        }
        $fullPath = base_path($packagePath);
        $workflowDir = $fullPath.DIRECTORY_SEPARATOR.'.github'.DIRECTORY_SEPARATOR.'workflows';
        $targetFile = $workflowDir.DIRECTORY_SEPARATOR.'run-tests.yml';

        if (File::exists($targetFile) && ! $force) {
            $this->warn('GitHub Actions workflow already exists at: .github/workflows/run-tests.yml');
            $this->line('  <comment>How to fix:</comment> Use the [--force] flag to overwrite:');
            $this->line("  <info>php artisan package:workflow {$package} --force</info>");

            return self::SUCCESS;
        }

        $yaml = $this->matrixGenerator->generatePackageWorkflowYaml($fullPath);

        File::ensureDirectoryExists($workflowDir);
        File::put($targetFile, $yaml);

        $this->info("✔ GitHub Actions test matrix generated for [{$package}]:");
        $this->line("  <comment>Location:</comment> {$packagePath}/.github/workflows/run-tests.yml");

        return self::SUCCESS;
    }
}
