<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerManager;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\PackageScaffolder;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceManager;

class PackageMakeCommand extends BasePackageCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:make
        {package : Package name in vendor/package format (e.g. acme/my-pkg) or single-word for fixed-vendor workspace}
        {--as= : Optional directory alias (flat workspaces only)}
        {--alias= : Optional directory alias (synonym for --as)}
        {--workspace= : The target workspace directory}
        {--install : Install the package via Composer immediately}
        {--dev : When installing, require as a development dependency}
        {--skills : Scaffold an agent skill in resources/skills}
        {--no-skills : Skip scaffolding an agent skill}
        {--skill-name= : Explicit name for the initial agent skill}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a local Laravel package with mandatory Git repository and initial v0.0.1 tag';

    public function __construct(
        WorkspaceManager $workspace,
        ComposerManager $composer,
        protected readonly PackageScaffolder $scaffolder,
    ) {
        parent::__construct($workspace, $composer);
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $install = (bool) $this->option('install');
        $dev = (bool) $this->option('dev');

        if ($dev && ! $install) {
            $this->error('The [--dev] option can only be used in combination with [--install].');
            $this->line('  <comment>How to fix:</comment> Pass both flags to create and install as a dev-dependency:');
            $this->line('  <info>php artisan package:make my-vendor/my-package --install --dev</info>');

            return self::FAILURE;
        }

        $workspace = (string) $this->option('workspace');

        if ($workspace === '') {
            $workspace = $this->workspace->getDefault();

            if (! $workspace) {
                $this->error('No default workspace is currently configured.');
                $this->line('  <comment>How to fix:</comment> Add a workspace first, or specify one via the --workspace option:');
                $this->line('  <info>php artisan workspace:add packages</info>');
                $this->line('  <info>php artisan package:make my-vendor/my-package --workspace=packages</info>');

                return self::FAILURE;
            }
        }

        $rawPackage = (string) $this->argument('package');
        $rawAlias = (string) ($this->option('as') ?: $this->option('alias'));
        $alias = trim($rawAlias) !== '' ? trim($rawAlias) : null;

        $scaffoldSkills = $this->option('no-skills')
            ? false
            : ($this->option('skills') || config('workspace.scaffold_agent_skills', true));

        $skillSlug = (string) $this->option('skill-name');

        try {
            $result = $this->scaffolder->scaffold(
                workspace: $workspace,
                rawPackage: $rawPackage,
                alias: $alias,
                scaffoldSkills: $scaffoldSkills,
                skillSlug: $skillSlug !== '' ? $skillSlug : null
            );
        } catch (WorkspaceException $e) {
            return $this->handleWorkspaceException($e);
        } catch (\Throwable $e) {
            $this->error("Failed to scaffold package: {$e->getMessage()}");

            return self::FAILURE;
        }

        $package = $result->package;
        $shortName = $result->shortName;
        $displayPath = $result->displayPath;

        $this->info("Package [{$package}] created successfully in [{$displayPath}].");
        $this->line('  <info>Git repository initialized with initial commit and tag v0.0.1.</info>');

        if ($alias !== null) {
            $this->warnIfDuplicateAlias($alias, $displayPath, $package);
        }

        if ($install) {
            $this->newLine();

            $exitCode = $this->call('package:install', [
                'package' => $shortName,
                '--dev' => $dev,
            ]);

            if ($exitCode !== self::SUCCESS) {
                $this->newLine();
                $this->warn('Notice: Package scaffolding completed, but automatic Composer installation failed.');
                $this->line("  Physical files remain intact in [{$displayPath}].");
                $this->line('  <comment>How to fix:</comment> Resolve the Composer error shown above, then link the package manually:');
                $this->line("  <info>php artisan package:install {$shortName}".($dev ? ' --dev' : '').'</info>');

                return $exitCode;
            }
        } else {
            $this->newLine();
            $this->line('  <comment>Hint:</comment> To link this package into your application via Composer, run:');
            $this->line("  <info>php artisan package:install {$shortName}</info>");
            $this->line("  Or as a dev-dependency: <info>php artisan package:install {$shortName} --dev</info>");
        }

        return self::SUCCESS;
    }
}
