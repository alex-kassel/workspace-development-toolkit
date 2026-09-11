<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class PackageMakeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:make {name : Package name (vendor/package for multi-vendor, or single-word for fixed-vendor workspace)} {--as= : Optional directory alias (flat workspaces only)} {--alias= : Optional directory alias (synonym for --as)} {--workspace= : The target workspace directory} {--install : Install the package via Composer immediately} {--dev : When installing, require as a development dependency}';

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

        // Composer name segment pattern: lowercase alphanumeric, dashes, dots, underscores
        $segmentPattern = '/^[a-z0-9]([_.-]?[a-z0-9]+)*$/';

        if ($workspaceVendor !== null) {
            // Flat 1-level workspace: vendor is fixed
            if (str_contains($normalizedInput, '/')) {
                [$providedVendor, $providedPackage] = explode('/', $normalizedInput, 2);
                $cleanProvidedVendor = strtolower(trim($providedVendor));
                $package = strtolower(trim($providedPackage));

                if ($cleanProvidedVendor !== $workspaceVendor) {
                    $this->error("Workspace [{$workspace}] has a fixed vendor [{$workspaceVendor}], but [{$providedVendor}] was provided.");
                    $this->line('  <comment>How to fix:</comment> Omit the vendor prefix or match the workspace vendor:');
                    $this->line("  <info>php artisan package:make {$package} --workspace={$workspace}</info>");

                    return self::FAILURE;
                }
            } else {
                $package = strtolower(trim($normalizedInput));
            }

            if (! preg_match($segmentPattern, $package)) {
                $suggested = Str::slug($package);
                $this->error("Invalid package name [{$rawName}]. Composer package names must contain only lowercase letters, numbers, dashes, underscores, and dots.");
                $this->line('  <comment>How to fix:</comment> Use a valid package name. Did you mean:');
                $this->line("  <info>php artisan package:make {$suggested} --workspace={$workspace}</info>");

                return self::FAILURE;
            }

            $vendor = $workspaceVendor;
            $name = "{$vendor}/{$package}";
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

            [$rawVendor, $rawPackage] = explode('/', $normalizedInput, 2);
            $vendor = strtolower(trim($rawVendor));
            $package = strtolower(trim($rawPackage));

            if (! preg_match($segmentPattern, $vendor) || ! preg_match($segmentPattern, $package)) {
                $suggestedVendor = Str::slug($vendor);
                $suggestedPackage = Str::slug($package);
                $this->error("Invalid package name [{$rawName}]. Composer vendor and package names must contain only lowercase letters, numbers, dashes, underscores, and dots.");
                $this->line('  <comment>How to fix:</comment> Use a valid vendor/package name. Did you mean:');
                $this->line("  <info>php artisan package:make {$suggestedVendor}/{$suggestedPackage} --workspace={$workspace}</info>");

                return self::FAILURE;
            }

            $name = "{$vendor}/{$package}";
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
        $frameworkVersion = $this->getApplication()->getVersion();
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

        $providerContent = <<<PHP
<?php

declare(strict_types=1);

namespace {$vendorNamespace}\\{$packageNamespace};

use Illuminate\Support\ServiceProvider;

class {$providerClass} extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}

PHP;

        File::put("{$packagePath}/src/{$providerClass}.php", $providerContent);

        Workspace::sync();

        if ($alias !== '') {
            $data = Workspace::load();
            $wsPackages = $data['workspaces'][$workspace]['packages'] ?? [];
            $newPackages = [];

            foreach ($wsPackages as $item) {
                $existingName = is_array($item) ? ($item['name'] ?? '') : (string) $item;
                if ($existingName === $shortName || $existingName === $dirName) {
                    continue;
                }
                $newPackages[] = $item;
            }

            $newPackages[] = [
                'name' => $shortName,
                'alias' => $alias,
            ];

            usort($newPackages, function ($a, $b) {
                $nameA = is_array($a) ? ($a['alias'] ?? $a['name']) : $a;
                $nameB = is_array($b) ? ($b['alias'] ?? $b['name']) : $b;

                return strcasecmp($nameA, $nameB);
            });

            $data['workspaces'][$workspace]['packages'] = $newPackages;
            Workspace::save($data);

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
}
