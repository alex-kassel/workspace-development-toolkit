<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Enums\DiagnosticSeverity;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerManager;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\PackageScaffolder;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceManager;
use Illuminate\Support\Facades\File;
use Laravel\Prompts\Prompt;

class PackageMakeCommand extends BasePackageCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:make
        {package? : Package name in vendor/package format (e.g. acme/my-pkg) or single-word for fixed-vendor workspace}
        {--as= : Optional directory alias (flat workspaces only)}
        {--alias= : Optional directory alias (synonym for --as)}
        {--workspace= : The target workspace directory}
        {--install : Install the package via Composer immediately}
        {--dev : When installing, require as a development dependency}
        {--skills : Scaffold default agent skills into .agents/skills/ in the new package}
        {--no-skills : Explicitly skip scaffolding agent skills}
        {--skill-name= : Explicit custom skill slug to scaffold (e.g. package-custom)}
        {--archetype= : Package archetype template (standard, domain, service, core, utility)}
        {--type= : Synonym for --archetype}';

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
                $this->line('  <comment>How to fix:</comment> Register a workspace first, or specify one via the --workspace option:');
                $this->line('  <info>php artisan workspace:register packages</info>');
                $this->line('  <info>php artisan package:make my-vendor/my-package --workspace=packages</info>');

                return self::FAILURE;
            }
        }

        $rawPackage = trim((string) $this->argument('package'));

        if ($rawPackage === '') {
            $workspaceVendor = $this->workspace->getWorkspaceVendor($workspace);
            $existing = array_map(
                fn ($p) => is_array($p) ? ($p['name'] ?? '') : (string) $p,
                $this->workspace->all()[$workspace]['packages'] ?? []
            );

            if ($this->input->isInteractive() && @stream_isatty(STDIN)) {
                $label = $workspaceVendor !== null
                    ? "Enter package name for [{$workspace}] (default vendor: {$workspaceVendor}):"
                    : "Enter package name with vendor for [{$workspace}] (e.g. vendor/my-package):";

                $placeholder = $workspaceVendor !== null ? 'billing' : 'my-vendor/my-package';

                $usePrompt = class_exists(Prompt::class);
                if ($usePrompt) {
                    $rawPackage = (string) \Laravel\Prompts\text(
                        label: $label,
                        placeholder: $placeholder,
                        required: true,
                        validate: function (string $value) use ($workspace, $workspaceVendor, $existing) {
                            $val = trim($value);
                            if ($val === '') {
                                return 'Package name cannot be empty.';
                            }

                            $validation = $this->workspace->validatePackageName($val, $workspaceVendor);
                            if (! $validation['isValid']) {
                                return $validation['error'];
                            }

                            $shortName = str_contains($val, '/') ? explode('/', $val)[1] : $val;
                            $targetDir = $workspaceVendor !== null
                                ? base_path("{$workspace}/{$shortName}")
                                : base_path("{$workspace}/{$val}");

                            if (in_array($val, $existing, true) || in_array($shortName, $existing, true) || File::isDirectory($targetDir)) {
                                $list = ! empty($existing) ? implode(', ', $existing) : 'none';

                                return "Package [{$val}] already exists in workspace [{$workspace}]. Existing packages: [{$list}]. Please enter a different name.";
                            }

                            return null;
                        }
                    );
                } else {
                    while (true) {
                        $entered = (string) $this->ask($label);
                        $val = trim($entered);
                        if ($val === '') {
                            continue;
                        }

                        $validation = $this->workspace->validatePackageName($val, $workspaceVendor);
                        if (! $validation['isValid']) {
                            $this->error($validation['error']);

                            continue;
                        }

                        $shortName = str_contains($val, '/') ? explode('/', $val)[1] : $val;
                        $targetDir = $workspaceVendor !== null
                            ? base_path("{$workspace}/{$shortName}")
                            : base_path("{$workspace}/{$val}");

                        if (in_array($val, $existing, true) || in_array($shortName, $existing, true) || File::isDirectory($targetDir)) {
                            $this->warn("Package [{$val}] already exists in workspace [{$workspace}]. Existing packages: ".implode(', ', $existing));

                            continue;
                        }

                        $rawPackage = $val;
                        break;
                    }
                }

                if ($rawPackage === '') {
                    return self::SUCCESS;
                }
            } else {
                $example = $workspaceVendor !== null ? 'my-package' : 'my-vendor/my-package';
                $this->dispatchDiagnostic(
                    code: 'CMD_ARGUMENT_REQUIRED',
                    message: 'Package name cannot be empty.',
                    severity: DiagnosticSeverity::Error,
                    context: [
                        'Target workspace' => $workspace,
                        'Existing packages' => empty($existing) ? ['(none)'] : $existing,
                    ],
                    remediationSteps: [
                        "php artisan package:make {$example} --workspace={$workspace}",
                    ],
                    agentGuidance: "Provide the package name as the first argument: 'php artisan package:make <name>'."
                );

                return self::FAILURE;
            }
        }
        $rawAlias = (string) ($this->option('as') ?: $this->option('alias'));
        $alias = trim($rawAlias) !== '' ? trim($rawAlias) : null;

        $scaffoldSkills = $this->option('no-skills')
            ? false
            : ($this->option('skills') || config('workspace.scaffold_agent_skills', true));

        $skillSlug = (string) $this->option('skill-name');
        $rawArchetype = (string) ($this->option('archetype') ?: $this->option('type'));
        $archetype = trim($rawArchetype) !== '' ? trim($rawArchetype) : null;

        try {
            $result = $this->scaffolder->scaffold(
                workspace: $workspace,
                rawPackage: $rawPackage,
                alias: $alias,
                scaffoldSkills: $scaffoldSkills,
                skillSlug: $skillSlug !== '' ? $skillSlug : null,
                archetype: $archetype
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
