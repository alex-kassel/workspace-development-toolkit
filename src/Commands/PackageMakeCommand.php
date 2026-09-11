<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class PackageMakeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:make {name : Package name (vendor/package for multi-vendor, or single-word for fixed-vendor workspace)} {--as= : Optional directory alias (flat workspaces only)} {--alias= : Optional directory alias (synonym for --as)} {--workspace= : The target workspace directory} {--install : Install the package via Composer immediately} {--dev : When installing, require as a development dependency} {--git : Initialize Git repository in package directory} {--skills : Scaffold an agent skill in resources/skills} {--no-skills : Skip scaffolding an agent skill} {--skill-name= : Explicit name for the initial agent skill}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a minimal local Laravel package';

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
            $workspace = Workspace::getDefault();

            if (! $workspace) {
                $this->error('No default workspace is currently configured.');
                $this->line('  <comment>How to fix:</comment> Add a workspace first, or specify one via the --workspace option:');
                $this->line('  <info>php artisan workspace:add packages</info>');
                $this->line('  <info>php artisan package:make my-vendor/my-package --workspace=packages</info>');

                return self::FAILURE;
            }
        }

        $workspace = trim(preg_replace('#[/\\\\]+#', '/', $workspace) ?? '', '/');
        $workspaces = Workspace::all();

        if (! array_key_exists($workspace, $workspaces)) {
            $available = empty($workspaces) ? 'none' : implode(', ', array_keys($workspaces));
            $this->error("Workspace [{$workspace}] is not registered. Available workspaces: [{$available}].");
            $this->line('  <comment>How to fix:</comment> Register the workspace first:');
            $this->line("  <info>php artisan workspace:add {$workspace}</info>");

            return self::FAILURE;
        }

        $workspaceVendor = Workspace::getWorkspaceVendor($workspace);
        $rawAlias = (string) ($this->option('as') ?: $this->option('alias'));
        $alias = trim($rawAlias);

        if ($alias !== '' && $workspaceVendor === null) {
            $this->error('Aliases are only supported in flat (fixed-vendor) workspaces.');
            $this->line("  <comment>Notice:</comment> Workspace [{$workspace}] is a nested multi-vendor workspace (e.g. packages/{vendor}/{package}).");

            return self::FAILURE;
        }

        if ($alias !== '' && ! preg_match('/^[a-zA-Z0-9_.-]+$/', $alias)) {
            $this->error("Invalid alias [{$alias}].");
            $this->line('  <comment>How to fix:</comment> Alias must contain only alphanumeric characters, dashes, underscores, and dots.');

            return self::FAILURE;
        }

        $rawName = (string) $this->argument('name');
        $normalizedInput = str_replace('\\', '/', trim($rawName));

        $validation = Workspace::validatePackageName($normalizedInput, $workspaceVendor);

        if ($workspaceVendor !== null) {
            // Flat 1-level workspace: vendor is fixed
            if (str_contains($normalizedInput, '/')) {
                [$providedVendor] = explode('/', $normalizedInput, 2);
                if (strtolower(trim($providedVendor)) !== strtolower($workspaceVendor)) {
                    $this->error("Workspace [{$workspace}] has a fixed vendor [{$workspaceVendor}], but [{$providedVendor}] was provided.");
                    $this->line('  <comment>How to fix:</comment> Omit the vendor prefix or match the workspace vendor:');
                    $this->line("  <info>php artisan package:make {$validation['package']} --workspace={$workspace}</info>");

                    return self::FAILURE;
                }
            }

            if (! $validation['isValid']) {
                $this->error($validation['error'] ?? "Invalid package name [{$rawName}].");
                if ($validation['suggestion'] !== null) {
                    $this->line('  <comment>How to fix:</comment> Use a valid package name. Did you mean:');
                    $this->line("  <info>php artisan package:make {$validation['suggestion']} --workspace={$workspace}</info>");
                }

                return self::FAILURE;
            }

            $vendor = $validation['vendor'];
            $package = $validation['package'];
            $name = $validation['fullName'];
            $shortName = $package;
            $dirName = $alias !== '' ? $alias : $package;
            $packagePath = base_path("{$workspace}/{$dirName}");
        } else {
            // Nested 2-level workspace: vendor is required
            if (! str_contains($normalizedInput, '/')) {
                $this->error("Workspace [{$workspace}] requires a vendor prefix in 'vendor/package' format.");
                $this->line('  <comment>How to fix:</comment> Specify both vendor and package name:');
                $this->line("  <info>php artisan package:make my-vendor/{$normalizedInput} --workspace={$workspace}</info>");

                return self::FAILURE;
            }

            if (! $validation['isValid']) {
                $this->error($validation['error'] ?? "Invalid package name [{$rawName}].");
                if ($validation['suggestion'] !== null) {
                    $this->line('  <comment>How to fix:</comment> Use a valid vendor/package name. Did you mean:');
                    $this->line("  <info>php artisan package:make {$validation['suggestion']} --workspace={$workspace}</info>");
                }

                return self::FAILURE;
            }

            $vendor = $validation['vendor'];
            $package = $validation['package'];
            $name = $validation['fullName'];
            $shortName = $name;
            $packagePath = base_path("{$workspace}/{$vendor}/{$package}");
        }

        if (File::isDirectory($packagePath)) {
            $this->error("Package directory [{$packagePath}] already exists on disk.");
            $this->line('  <comment>How to fix:</comment> Choose a different package name, or permanently delete the existing package:');
            $this->line("  <info>php artisan package:delete {$shortName}</info>");

            return self::FAILURE;
        }

        $vendorNamespace = Str::studly(str_replace(['.', '-'], '_', $vendor));
        $packageNamespace = Str::studly(str_replace(['.', '-'], '_', $package));
        $providerClass = "{$packageNamespace}ServiceProvider";

        File::makeDirectory("{$packagePath}/src", 0755, true, true);

        // Determine dynamic illuminate/support version constraint based on current Laravel environment
        $frameworkVersion = $this->getApplication()?->getVersion() ?? '11.0.0';
        $currentMajor = 11;
        if (preg_match('/^(\d+)/', $frameworkVersion, $matches)) {
            $currentMajor = max(11, (int) $matches[1]);
        }
        $supportedMajors = [];
        for ($v = 11; $v <= $currentMajor; $v++) {
            $supportedMajors[] = "^{$v}.0";
        }
        $illuminateConstraint = implode('|', $supportedMajors);

        $composerJson = json_encode([
            'name' => $name,
            'type' => 'library',
            'version' => '0.0.1',
            'require' => [
                'php' => '^8.2',
                'illuminate/support' => $illuminateConstraint,
            ],
            'autoload' => [
                'psr-4' => [
                    "{$vendorNamespace}\\{$packageNamespace}\\" => 'src/',
                ],
            ],
            'extra' => [
                'laravel' => [
                    'providers' => [
                        "{$vendorNamespace}\\{$packageNamespace}\\{$providerClass}",
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        File::put("{$packagePath}/composer.json", $composerJson."\n");

        File::makeDirectory("{$packagePath}/config", 0755, true, true);
        $configContent = <<<'PHP'
<?php

declare(strict_types=1);

return [
    /*
     |--------------------------------------------------------------------------
     | Agent Skills Target Path
     |--------------------------------------------------------------------------
     |
     | Relative path(s) from base_path() where agent skills should be published.
     | Supports a single string path or an array of multiple paths.
     |
     */
    'skills_path' => '.agents/skills',
];

PHP;
        File::put("{$packagePath}/config/{$package}.php", $configContent);

        $providerContent = <<<PHP
<?php

declare(strict_types=1);

namespace {$vendorNamespace}\\{$packageNamespace};

use Illuminate\Support\ServiceProvider;

class {$providerClass} extends ServiceProvider
{
    public function register(): void
    {
        \$this->mergeConfigFrom(
            __DIR__.'/../config/{$package}.php',
            '{$package}'
        );
    }

    public function boot(): void
    {
        if (\$this->app->runningInConsole()) {
            \$this->publishes([
                __DIR__.'/../config/{$package}.php' => config_path('{$package}.php'),
            ], '{$package}-config');

            \$skillsSource = __DIR__.'/../resources/skills';
            if (is_dir(\$skillsSource)) {
                \$targetPaths = (array) config('{$package}.skills_path', ['.agents/skills']);
                \$publishes = [];

                foreach (\$targetPaths as \$targetPath) {
                    \$publishes[\$skillsSource] = base_path(\$targetPath);
                }

                \$this->publishes(\$publishes, '{$package}-skills');
            }
        }
    }
}

PHP;

        File::put("{$packagePath}/src/{$providerClass}.php", $providerContent);

        $replacements = [
            '{{ vendor }}' => $vendor,
            '{{ package }}' => $package,
            '{{ vendorNamespace }}' => $vendorNamespace,
            '{{ packageNamespace }}' => $packageNamespace,
            '{{ year }}' => date('Y'),
            '{{ illuminate_constraint }}' => $illuminateConstraint,
            '{{ providerClass }}' => $providerClass,
        ];

        File::put("{$packagePath}/.gitattributes", $this->renderStub('gitattributes', $replacements));
        File::put("{$packagePath}/.gitignore", $this->renderStub('gitignore', $replacements));
        File::put("{$packagePath}/phpunit.xml", $this->renderStub('phpunit.xml', $replacements));
        File::put("{$packagePath}/phpstan.neon", $this->renderStub('phpstan.neon', $replacements));
        File::put("{$packagePath}/CHANGELOG.md", $this->renderStub('CHANGELOG.md', $replacements));
        File::put("{$packagePath}/README.md", $this->renderStub('README.md', $replacements));

        File::makeDirectory("{$packagePath}/tests", 0755, true, true);
        File::put("{$packagePath}/tests/TestCase.php", $this->renderStub('TestCase.php', $replacements));
        File::put("{$packagePath}/tests/bootstrap.php", $this->renderStub('bootstrap.php', $replacements));

        File::makeDirectory("{$packagePath}/tests/Unit", 0755, true, true);
        File::put("{$packagePath}/tests/Unit/.gitkeep", '');

        $scaffoldSkills = $this->option('no-skills')
            ? false
            : ($this->option('skills') || config('workspace.scaffold_agent_skills', true));

        if ($scaffoldSkills) {
            $skillSlug = (string) $this->option('skill-name');
            if ($skillSlug === '') {
                $skillSlug = Str::kebab($package);
            }

            $skillsDir = "{$packagePath}/resources/skills/{$skillSlug}";
            File::makeDirectory($skillsDir, 0755, true, true);

            $skillContent = <<<MARKDOWN
---
name: {$skillSlug}
origin: {$name}
version: 0.0.1
status: draft
description: >-
  TODO: Operational agent skill for {$name} package.
---

# {$skillSlug} Skill

> [!NOTE]
> This skill is currently in **draft** status.
> Fill in instructions and workflows for AI agents, then remove `status: draft` to activate publishing.

---

## Operational Workflow

### Phase 0: Tooling Verification & Bootstrapping
Before performing actions with this package:
1. Verify if the package service or commands are available:
   ```bash
   composer show {$name}
   ```
2. **If installed**: Proceed to next phase.
3. **If missing**:
   - Check your environment execution policy:
     - If authorized to install dependencies autonomously:
       ```bash
       composer require {$name}
       ```
     - Otherwise, request human confirmation before modifying dependencies:
       *"The package [{$name}] is required for this operation. May I install it via composer require?"*

MARKDOWN;

            File::put("{$skillsDir}/SKILL.md", $skillContent);
        }

        Workspace::sync();

        if ($alias !== '') {
            Workspace::registerPackageAlias($workspace, $shortName, $alias);

            $duplicates = Workspace::findDuplicateAliases($alias, "{$workspace}/{$dirName}");
            if (! empty($duplicates)) {
                $this->newLine();
                $this->warn("Notice: The alias/name [{$alias}] is also used by another package:");
                foreach ($duplicates as $duplicate) {
                    $this->line("  • {$duplicate}");
                }
                $this->newLine();
                $this->line("  <comment>Hint:</comment> Both packages will work normally in Composer, but resolving by short name '{$alias}' will be ambiguous.");
            }
        }

        $displayPath = trim(str_replace(base_path(), '', $packagePath), '/\\');
        $this->info("Package [{$name}] created successfully in [{$displayPath}].");

        if ($this->option('git')) {
            $this->initializeGitRepository($packagePath, $name);
        }

        if ($install) {
            $this->newLine();

            $exitCode = $this->call('package:install', [
                'name' => $shortName,
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

    /**
     * Render a stub file with replacements.
     *
     * @param  array<string, string>  $replacements
     */
    protected function renderStub(string $name, array $replacements): string
    {
        $content = $this->getStubContent($name);

        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }

    /**
     * Get stub content from custom workspace stubs or fallback to package stubs.
     */
    protected function getStubContent(string $name): string
    {
        $customStubPath = base_path("stubs/workspace/{$name}.stub");

        if (File::exists($customStubPath)) {
            return File::get($customStubPath);
        }

        $packageStubPath = __DIR__."/../../stubs/package/{$name}.stub";

        if (File::exists($packageStubPath)) {
            return File::get($packageStubPath);
        }

        throw new \RuntimeException("Stub file [{$name}.stub] not found.");
    }

    /**
     * Initialize Git repository with an initial commit.
     */
    protected function initializeGitRepository(string $packagePath, string $name): void
    {
        $gitDir = $packagePath.'/.git';
        if (File::isDirectory($gitDir)) {
            $this->line('  <comment>Notice:</comment> Git repository is already initialized.');

            return;
        }

        $initResult = Process::path($packagePath)->run(['git', 'init']);
        if (! $initResult->successful()) {
            $this->warn('Notice: Failed to initialize Git repository: '.$initResult->errorOutput());

            return;
        }

        Process::path($packagePath)->run(['git', 'add', '.']);
        $commitResult = Process::path($packagePath)->run([
            'git',
            'commit',
            '-m',
            "feat: scaffold initial {$name} package",
        ]);

        if (! $commitResult->successful()) {
            $this->warn('Notice: Failed to create initial commit: '.$commitResult->errorOutput());

            return;
        }

        $this->line('  <info>Git repository initialized with initial commit.</info>');
    }
}
