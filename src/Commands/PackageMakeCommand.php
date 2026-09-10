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
    protected $signature = 'package:make {name : Package name (vendor/package for multi-vendor, or single-word for fixed-vendor workspace)} {--workspace= : The target workspace directory} {--install : Install the package via Composer immediately} {--dev : When installing, require as a development dependency}';

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
        $rawName = (string) $this->argument('name');
        $normalizedInput = str_replace('\\', '/', trim($rawName));

        if ($workspaceVendor !== null) {
            // Flat 1-level workspace: vendor is fixed
            if (str_contains($normalizedInput, '/')) {
                [$providedVendor, $providedPackage] = explode('/', $normalizedInput, 2);
                $cleanProvidedVendor = Str::slug($providedVendor);
                $package = Str::slug($providedPackage);

                if ($cleanProvidedVendor !== $workspaceVendor) {
                    $this->error("Workspace [{$workspace}] has a fixed vendor [{$workspaceVendor}], but [{$providedVendor}] was provided.");
                    $this->line('  <comment>How to fix:</comment> Omit the vendor prefix or use the workspace vendor:');
                    $this->line("  <info>php artisan package:make {$package} --workspace={$workspace}</info>");

                    return self::FAILURE;
                }
            } else {
                $package = Str::slug($normalizedInput);
            }

            if ($package === '') {
                $this->error("Invalid package name [{$rawName}]. Package name cannot be empty.");
                $this->line('  <comment>How to fix:</comment> Provide a valid package name:');
                $this->line("  <info>php artisan package:make my-package --workspace={$workspace}</info>");

                return self::FAILURE;
            }

            $vendor = $workspaceVendor;
            $name = "{$vendor}/{$package}";
            $shortName = $package;
            $packagePath = base_path("{$workspace}/{$package}");
        } else {
            // Nested 2-level workspace: vendor is required
            if (! str_contains($normalizedInput, '/')) {
                $this->error("Workspace [{$workspace}] requires a vendor prefix in 'vendor/package' format.");
                $this->line('  <comment>How to fix:</comment> Specify both vendor and package name:');
                $this->line("  <info>php artisan package:make my-vendor/{$normalizedInput} --workspace={$workspace}</info>");

                return self::FAILURE;
            }

            [$rawVendor, $rawPackage] = explode('/', $normalizedInput, 2);
            $vendor = Str::slug($rawVendor);
            $package = Str::slug($rawPackage);

            if ($vendor === '' || $package === '') {
                $this->error("Invalid package name [{$rawName}]. Vendor and package names cannot be empty after sanitization.");
                $this->line('  <comment>How to fix:</comment> Use valid alphanumeric characters:');
                $this->line('  <info>php artisan package:make my-vendor/my-package</info>');

                return self::FAILURE;
            }

            $name = "{$vendor}/{$package}";
            $shortName = $name;
            $packagePath = base_path("{$workspace}/{$vendor}/{$package}");
        }

        if ($rawName !== $shortName && $rawName !== $name) {
            $this->line("  <comment>Notice:</comment> Converted package name [{$rawName}] to canonical Composer format [{$name}].");
        }

        if (File::isDirectory($packagePath)) {
            $this->error("Package directory [{$packagePath}] already exists on disk.");
            $this->line('  <comment>How to fix:</comment> Choose a different package name, or permanently delete the existing package:');
            $this->line("  <info>php artisan package:delete {$shortName}</info>");

            return self::FAILURE;
        }

        $vendorNamespace = Str::studly($vendor);
        $packageNamespace = Str::studly($package);
        $providerClass = "{$packageNamespace}ServiceProvider";

        File::makeDirectory("{$packagePath}/src", 0755, true, true);

        $composerJson = json_encode([
            'name' => $name,
            'type' => 'library',
            'version' => '0.0.1',
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

        $displayPath = trim(str_replace(base_path(), '', $packagePath), '/\\');
        $this->info("Package [{$name}] created successfully in [{$displayPath}].");

        if ($install) {
            $this->newLine();

            $exitCode = $this->call('package:install', [
                'name' => $shortName,
                '--dev' => $dev,
            ]);

            if ($exitCode !== self::SUCCESS) {
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
