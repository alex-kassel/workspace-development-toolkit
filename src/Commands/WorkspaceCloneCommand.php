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
        {--dev : When installing, require as a development dependency (--dev)}';

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

        if ($isSelf) {
            // Determine our own repository URL and install mode
            $repoUrl = $this->resolveSelfRepositoryUrl($useSsh);
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

            $repoUrl = $this->normalizeRepositoryUrl($rawRepo, $useSsh);
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
        $alias = (string) ($this->option('as') ?: $this->option('alias'));

        if ($alias !== '' && $workspaceVendor === null) {
            $this->error('Aliases are only supported in flat (fixed-vendor) workspaces.');
            $this->line("  <comment>Notice:</comment> Workspace [{$workspace}] is a nested multi-vendor workspace (e.g. packages/{vendor}/{package}).");

            return self::FAILURE;
        }

        // Pre-parse vendor and package hints from URL (e.g. vendor/package)
        [$inferredVendor, $inferredPackage] = $this->parseRepoVendorAndPackage($repoUrl);

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

        // Execute git clone
        $cloneResult = Process::timeout(300)->run(['git', 'clone', $repoUrl, $fullTargetPath]);

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

            $requireArgs = ['composer', 'require', "{$canonicalComposerName}:@dev"];
            if ($dev) {
                $requireArgs[] = '--dev';
            }

            $installResult = Process::path(base_path())->timeout(300)->run($requireArgs);

            if (! $installResult->successful()) {
                $this->error("Failed to install package [{$canonicalComposerName}] via Composer.");
                $this->line("  <comment>Composer output:</comment>\n".trim($installResult->errorOutput() ?: $installResult->output()));
                $this->line('  <comment>How to fix:</comment> Run composer require manually:');
                $this->line("  <info>composer require {$canonicalComposerName}:@dev".($dev ? ' --dev' : '').'</info>');

                return self::FAILURE;
            }

            $this->info("Package [{$canonicalComposerName}] is now symlinked to [{$relativeTargetPath}]!");
        }

        $this->newLine();
        $this->line('  <comment>Next steps:</comment>');
        $this->line('  • Check registered packages: <info>php artisan workspace:list</info>');
        if (! $install && $canonicalComposerName) {
            $refName = $alias !== '' ? $alias : $canonicalComposerName;
            $this->line("  • Link into Composer:        <info>php artisan package:install {$refName}".($dev ? ' --dev' : '').'</info>');
        }

        return self::SUCCESS;
    }

    /**
     * Resolve the git repository URL of the workspace toolkit itself.
     */
    protected function resolveSelfRepositoryUrl(bool $useSsh): string
    {
        $packageDir = dirname(__DIR__, 2);

        // 1. Try reading from git config inside package directory
        if (File::isDirectory("{$packageDir}/.git")) {
            $gitRemote = Process::path($packageDir)->run(['git', 'config', '--get', 'remote.origin.url']);
            if ($gitRemote->successful() && trim($gitRemote->output()) !== '') {
                $url = trim($gitRemote->output());

                return $this->formatUrlProtocol($url, $useSsh);
            }
        }

        // 2. Try composer installed.json in root
        $installedJsonPath = base_path('vendor/composer/installed.json');
        if (File::exists($installedJsonPath)) {
            $installed = json_decode(File::get($installedJsonPath), true);
            $packages = $installed['packages'] ?? $installed;
            foreach ($packages as $pkg) {
                if (($pkg['name'] ?? '') === 'alex-kassel/workspace-development-toolkit') {
                    $sourceUrl = $pkg['source']['url'] ?? null;
                    if ($sourceUrl) {
                        return $this->formatUrlProtocol($sourceUrl, $useSsh);
                    }
                }
            }
        }

        // 3. Fallback to package's own composer.json name on GitHub
        $composerJsonPath = "{$packageDir}/composer.json";
        $pkgName = 'alex-kassel/workspace-development-toolkit';
        if (File::exists($composerJsonPath)) {
            $data = json_decode(File::get($composerJsonPath), true);
            $pkgName = $data['name'] ?? $pkgName;
        }

        return $useSsh
            ? "git@github.com:{$pkgName}.git"
            : "https://github.com/{$pkgName}.git";
    }

    /**
     * Normalize repository string (e.g. "vendor/package" shorthand -> GitHub URL).
     */
    protected function normalizeRepositoryUrl(string $repo, bool $useSsh): string
    {
        $repo = trim($repo);

        // Standard Git or SSH URL
        if (str_starts_with($repo, 'git@') || str_starts_with($repo, 'http://') || str_starts_with($repo, 'https://') || str_starts_with($repo, 'ssh://')) {
            return $this->formatUrlProtocol($repo, $useSsh);
        }

        // Shorthand vendor/package: resolve via repository_template
        if (preg_match('#^[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+$#', $repo)) {
            $url = Workspace::resolvePackageCloneUrl($repo);

            return $this->formatUrlProtocol($url, $useSsh);
        }

        return $repo;
    }

    /**
     * Format URL according to preferred protocol (SSH vs HTTPS).
     */
    protected function formatUrlProtocol(string $url, bool $useSsh): string
    {
        if ($useSsh) {
            // If https://github.com/vendor/package.git -> git@github.com:vendor/package.git
            if (preg_match('#^https?://github\.com/([^/]+)/([^/]+?)(?:\.git)?$#i', $url, $matches)) {
                return "git@github.com:{$matches[1]}/{$matches[2]}.git";
            }
        } else {
            // If git@github.com:vendor/package.git -> https://github.com/vendor/package.git
            if (preg_match('#^git@github\.com:([^/]+)/([^/]+?)(?:\.git)?$#i', $url, $matches)) {
                return "https://github.com/{$matches[1]}/{$matches[2]}.git";
            }
        }

        return $url;
    }

    /**
     * Parse vendor and package names from repository URL or shorthand.
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected function parseRepoVendorAndPackage(string $url): array
    {
        // git@github.com:vendor/package.git or https://github.com/vendor/package.git
        if (preg_match('#[:/]([a-zA-Z0-9_.-]+)/([a-zA-Z0-9_.-]+?)(?:\.git)?$#', $url, $matches)) {
            return [$matches[1], $matches[2]];
        }

        return [null, null];
    }

    /**
     * Check if an option was explicitly provided by the user.
     */
    protected function hasExplicitOption(string $name): bool
    {
        return $this->hasOption($name) && $this->input->hasParameterOption("--{$name}");
    }
}
