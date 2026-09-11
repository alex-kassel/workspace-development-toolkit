<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class WorkspaceCloneCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workspace:clone
        {repository? : Git repository URL or GitHub shorthand (e.g. vendor/package)}
        {--self : Clone the workspace toolkit itself}
        {--workspace= : The target workspace directory (defaults to configured default workspace)}
        {--as= : Optional directory alias (flat workspaces only)}
        {--alias= : Optional directory alias (flat workspaces only)}
        {--ssh : Prefer SSH clone format (git@github.com:vendor/package.git) for GitHub shorthands}
        {--install : Register and symlink the cloned package into Composer immediately}
        {--dev : When installing, require as a development dependency (--dev)}
        {--recursive : Recursively clone dependencies from trusted organizations}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clone a package from a Git/GitHub repository into a workspace and optionally symlink via Composer';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isSelf = (bool) $this->option('self');
        $rawRepo = (string) $this->argument('repository');
        $useSsh = (bool) $this->option('ssh');
        $install = (bool) $this->option('install');
        $dev = (bool) $this->option('dev');
        $recursive = (bool) $this->option('recursive');

        if ($isSelf) {
            // Determine our own repository URL and install mode
            $repoUrl = Workspace::resolveSelfRepositoryUrl($useSsh);
            // Self-cloned toolkit is naturally a dev dependency by default
            $install = true;
            $dev = true;
        } else {
            if ($rawRepo === '') {
                $this->error('Please specify a repository URL/shorthand or pass the [--self] flag.');
                $this->line('  <comment>Usage examples:</comment>');
                $this->line('  <info>php artisan workspace:clone vendor/package</info>');
                $this->line('  <info>php artisan workspace:clone git@github.com:vendor/package.git</info>');
                $this->line('  <info>php artisan workspace:clone --self</info>');

                return self::FAILURE;
            }

            $repoUrl = Workspace::normalizeRepositoryUrl($rawRepo, $useSsh);
        }

        if ($dev && ! $install) {
            $this->error('The [--dev] option can only be used in combination with [--install].');
            $this->line('  <comment>How to fix:</comment> Pass [--install] along with [--dev]:');
            $this->line('  <info>php artisan workspace:clone '.($rawRepo ?: '--self').' --install --dev</info>');

            return self::FAILURE;
        }

        // Resolve workspace
        $rawWorkspace = (string) $this->option('workspace');
        if ($rawWorkspace === '') {
            $workspace = Workspace::getDefault();
            if (! $workspace) {
                $this->error('No default workspace is currently configured.');
                $this->line('  <comment>How to fix:</comment> Add a workspace first, or specify one via the [--workspace] option:');
                $this->line('  <info>php artisan workspace:add packages</info>');
                $this->line('  <info>php artisan workspace:clone '.($rawRepo ?: '--self').' --workspace=packages</info>');

                return self::FAILURE;
            }
        } else {
            $workspace = trim(preg_replace('#[/\\\\]+#', '/', $rawWorkspace) ?? '', '/');
        }

        $workspaces = Workspace::all();
        if (! isset($workspaces[$workspace])) {
            $this->error("Workspace [{$workspace}] does not exist.");
            $this->line('  <comment>How to fix:</comment> You can add this workspace first using:');
            $this->line("  <info>php artisan workspace:add {$workspace}</info>");

            return self::FAILURE;
        }

        $workspaceVendor = Workspace::getWorkspaceVendor($workspace);
        $alias = trim((string) ($this->option('as') ?: $this->option('alias')));

        if ($alias !== '') {
            if ($workspaceVendor === null) {
                $this->error('Aliases are only supported in flat (fixed-vendor) workspaces.');
                $this->line("  <comment>Notice:</comment> Workspace [{$workspace}] is a nested multi-vendor workspace (e.g. packages/{vendor}/{package}).");

                return self::FAILURE;
            }

            if (! preg_match('/^[a-zA-Z0-9_.-]+$/', $alias) || str_contains($alias, '/') || str_contains($alias, '\\') || $alias === '.' || $alias === '..') {
                $this->error("Invalid alias [{$alias}].");
                $this->line('  <comment>Notice:</comment> Alias must contain only alphanumeric characters, dashes, underscores, and dots.');

                return self::FAILURE;
            }
        }

        // Pre-parse vendor and package hints from URL (e.g. vendor/package)
        [$inferredVendor, $inferredPackage] = Workspace::parseRepoVendorAndPackage($repoUrl);

        if ($workspaceVendor !== null) {
            // Fixed-vendor workspace (flat): labs/{package} or labs/{alias}
            if ($inferredVendor !== null && strtolower($inferredVendor) !== strtolower($workspaceVendor)) {
                $this->error("Vendor mismatch: repository vendor [{$inferredVendor}] does not match fixed workspace vendor [{$workspaceVendor}].");
                $this->line('  <comment>How to fix:</comment> Clone this package into a multi-vendor workspace (e.g. packages), or use a matching repository.');

                return self::FAILURE;
            }

            $packageName = $alias !== '' ? $alias : ($inferredPackage ?? basename(rtrim($repoUrl, '/'), '.git'));
            $relativeTargetPath = "{$workspace}/{$packageName}";
        } else {
            // Multi-vendor workspace (nested): packages/{vendor}/{package}
            $vendorName = $inferredVendor ?? 'packages';
            $packageName = $inferredPackage ?? basename(rtrim($repoUrl, '/'), '.git');
            $relativeTargetPath = "{$workspace}/{$vendorName}/{$packageName}";
        }

        // Validate target path stays strictly inside workspace boundary
        $normalizedWorkspace = trim(str_replace('\\', '/', $workspace), '/');
        $normalizedRelativeTarget = trim(str_replace('\\', '/', $relativeTargetPath), '/');
        if (! str_starts_with($normalizedRelativeTarget, "{$normalizedWorkspace}/") || str_contains($normalizedRelativeTarget, '..')) {
            $this->error("Invalid target path [{$relativeTargetPath}]. Target must reside within workspace [{$workspace}].");

            return self::FAILURE;
        }

        $fullTargetPath = base_path($relativeTargetPath);

        if (File::exists($fullTargetPath)) {
            $this->error("Target directory [{$relativeTargetPath}] already exists.");
            $this->line('  <comment>How to fix:</comment> Delete or rename the existing directory, or use a different workspace:');
            $this->line("  <info>php artisan package:delete {$relativeTargetPath} --force</info>");

            return self::FAILURE;
        }

        $this->info("Cloning [{$repoUrl}] into [{$relativeTargetPath}]...");

        // Ensure parent directory exists
        File::ensureDirectoryExists(dirname($fullTargetPath));

        $timeout = (int) config('workspace.process_timeout', 300);

        // Execute git clone
        $cloneResult = Process::timeout($timeout)->run(['git', 'clone', $repoUrl, $fullTargetPath]);

        if (! $cloneResult->successful()) {
            $this->error("Failed to clone repository [{$repoUrl}].");
            $this->line("  <comment>Git output:</comment>\n".trim($cloneResult->errorOutput() ?: $cloneResult->output()));
            $this->line('  <comment>How to fix:</comment> Verify that git is installed, the URL is correct, and you have read access (check SSH keys for private repositories).');

            // Clean up directory if left partially created
            if (File::isDirectory($fullTargetPath)) {
                File::deleteDirectory($fullTargetPath);
            }

            return self::FAILURE;
        }

        // Verify cloned repository has composer.json
        $clonedComposerPath = "{$fullTargetPath}/composer.json";
        if (! File::exists($clonedComposerPath)) {
            $this->warn("Cloned repository does not contain a composer.json file at [{$relativeTargetPath}/composer.json].");
        }

        // Sync workspace registry
        Workspace::sync();

        $canonicalComposerName = null;
        if (File::exists($clonedComposerPath)) {
            $pkgData = json_decode(File::get($clonedComposerPath), true);
            $canonicalComposerName = $pkgData['name'] ?? null;
        }

        $recordedName = $canonicalComposerName ?: $packageName;
        if ($workspaceVendor !== null) {
            $recordedName = str_starts_with($recordedName, "{$workspaceVendor}/")
                ? substr($recordedName, strlen("{$workspaceVendor}/"))
                : $recordedName;
        }

        $isShorthand = ! $isSelf && preg_match('#^[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+$#', trim($rawRepo));
        $customUrl = ($isShorthand || $isSelf) ? null : $repoUrl;

        // Store package entry with optional custom URL and optional alias in workspace.json
        Workspace::recordPackage($workspace, $recordedName, $alias !== '' ? $alias : null, $customUrl);

        if ($alias !== '') {
            Workspace::aliasPackage($canonicalComposerName ?: $packageName, $alias);
        }

        $this->info("Repository successfully cloned to [{$relativeTargetPath}].");

        // Check if the chosen alias already exists elsewhere
        if ($alias !== '') {
            $duplicates = Workspace::findDuplicateAliases($alias, $relativeTargetPath);
            if (! empty($duplicates)) {
                $this->newLine();
                $this->warn("Notice: The alias/name [{$alias}] is also used by another package:");
                foreach ($duplicates as $duplicate) {
                    $this->line("  • {$duplicate}");
                }
                $this->newLine();
                $this->line("  <comment>Hint:</comment> Both packages will work normally in Composer, but resolving by short name '{$alias}' will be ambiguous.");
                $this->line('  If you wish to differentiate them, you can assign a unique alias:');
                $this->line("  <info>php artisan package:alias {$relativeTargetPath} UniqueAlias</info>");
            }
        }

        // Optional symlinking via Composer
        if ($install && $canonicalComposerName) {
            $this->info("Registering and symlinking [{$canonicalComposerName}] into root application...");

            $requireArgs = ['require', "{$canonicalComposerName}:@dev"];
            if ($dev) {
                $requireArgs[] = '--dev';
            }

            try {
                $installResult = Workspace::runComposer($requireArgs, $timeout);

                if (! $installResult->successful()) {
                    $this->error("Failed to install package [{$canonicalComposerName}] via Composer.");
                    $this->line("  <comment>Composer output:</comment>\n".trim($installResult->errorOutput() ?: $installResult->output()));
                    $this->line('  <comment>How to fix:</comment> Run composer require manually:');
                    $this->line("  <info>composer require {$canonicalComposerName}:@dev".($dev ? ' --dev' : '').'</info>');

                    return self::FAILURE;
                }
            } catch (\Throwable $e) {
                $this->error("Failed to execute Composer: {$e->getMessage()}");

                return self::FAILURE;
            }

            $this->info("Package [{$canonicalComposerName}] is now symlinked to [{$relativeTargetPath}]!");
        }

        // Recursive cloning of dependencies from trusted organizations
        if ($recursive && File::exists($clonedComposerPath)) {
            $visited = [$canonicalComposerName ?? $packageName => true];
            $this->cloneDependenciesRecursively($clonedComposerPath, $workspace, $useSsh, $install, $dev, $timeout, $visited);
        }

        $this->newLine();
        $this->line('  <comment>Next steps:</comment>');
        $this->line('  • Check registered packages: <info>php artisan workspace:list</info>');
        if (! $install && $canonicalComposerName) {
            $refName = $alias !== '' ? $alias : $canonicalComposerName;
            $this->line("  • Link into Composer:        <info>php artisan package:install {$refName}</info>");
        }

        return self::SUCCESS;
    }

    /**
     * Recursively clone dependencies from trusted organizations.
     *
     * @param  array<string, bool>  $visited
     */
    protected function cloneDependenciesRecursively(
        string $composerPath,
        string $workspace,
        bool $useSsh,
        bool $install,
        bool $dev,
        int $timeout,
        array &$visited
    ): void {
        $trustedOrgs = (array) config('workspace.trusted_organizations', []);
        if (empty($trustedOrgs) || ! File::exists($composerPath)) {
            return;
        }

        $content = json_decode(File::get($composerPath), true);
        if (! is_array($content)) {
            return;
        }

        $dependencies = array_merge(
            array_keys($content['require'] ?? []),
            array_keys($content['require-dev'] ?? [])
        );

        $workspaceVendor = Workspace::getWorkspaceVendor($workspace);

        foreach ($dependencies as $dep) {
            if (! is_string($dep) || ! str_contains($dep, '/')) {
                continue;
            }

            [$depVendor, $depPackage] = explode('/', $dep, 2);

            // Only process dependencies belonging to trusted organizations
            if (! in_array($depVendor, $trustedOrgs, true)) {
                continue;
            }

            // Cycle detection
            if (isset($visited[$dep])) {
                continue;
            }

            $visited[$dep] = true;

            // Determine target path in workspace
            if ($workspaceVendor !== null) {
                if (strtolower($depVendor) !== strtolower($workspaceVendor)) {
                    // Cannot clone a dependency from a different vendor into a fixed-vendor workspace
                    $this->warn("Skipping recursive dependency [{$dep}]: vendor [{$depVendor}] does not match fixed workspace vendor [{$workspaceVendor}].");

                    continue;
                }
                $relTarget = "{$workspace}/{$depPackage}";
            } else {
                $relTarget = "{$workspace}/{$depVendor}/{$depPackage}";
            }

            $fullTarget = base_path($relTarget);
            if (File::exists($fullTarget)) {
                $this->line("Dependency [{$dep}] already exists at [{$relTarget}], inspecting nested dependencies...");
                $depComposerPath = "{$fullTarget}/composer.json";
                if (File::exists($depComposerPath)) {
                    $this->cloneDependenciesRecursively($depComposerPath, $workspace, $useSsh, $install, $dev, $timeout, $visited);
                }

                continue;
            }

            $depRepoUrl = Workspace::normalizeRepositoryUrl($dep, $useSsh);
            $this->info("Recursively cloning dependency [{$dep}] into [{$relTarget}]...");

            File::ensureDirectoryExists(dirname($fullTarget));
            $cloneResult = Process::timeout($timeout)->run(['git', 'clone', $depRepoUrl, $fullTarget]);

            if (! $cloneResult->successful()) {
                $this->warn("Failed to clone dependency [{$dep}] from [{$depRepoUrl}].");

                if (File::isDirectory($fullTarget)) {
                    File::deleteDirectory($fullTarget);
                }

                continue;
            }

            // Sync and record
            Workspace::sync();
            $recorded = $workspaceVendor !== null ? $depPackage : $dep;
            Workspace::recordPackage($workspace, $recorded, null, null);

            $depComposerPath = "{$fullTarget}/composer.json";
            if ($install) {
                $this->info("Registering and symlinking dependency [{$dep}] into root application...");
                $requireArgs = ['require', "{$dep}:@dev"];
                if ($dev) {
                    $requireArgs[] = '--dev';
                }
                try {
                    Workspace::runComposer($requireArgs, $timeout);
                } catch (\Throwable $e) {
                    $this->warn("Failed to install dependency [{$dep}]: {$e->getMessage()}");
                }
            }

            if (File::exists($depComposerPath)) {
                $this->cloneDependenciesRecursively($depComposerPath, $workspace, $useSsh, $install, $dev, $timeout, $visited);
            }
        }
    }
}
