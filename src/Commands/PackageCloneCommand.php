<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerManager;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\GitDiagnosticService;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\PackageCloner;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceManager;
use Illuminate\Support\Facades\File;

class PackageCloneCommand extends BasePackageCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:clone
        {package? : Git repository URL or GitHub shorthand (format: vendor/package, e.g. acme/my-pkg)}
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

    public function __construct(
        WorkspaceManager $workspace,
        ComposerManager $composer,
        protected readonly GitDiagnosticService $diagnostics,
        protected readonly PackageCloner $cloner,
    ) {
        parent::__construct($workspace, $composer);
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isSelf = (bool) $this->option('self');
        $rawPackage = trim((string) $this->argument('package'));
        $useSsh = (bool) $this->option('ssh');
        $install = (bool) $this->option('install');
        $dev = (bool) $this->option('dev');
        $recursive = (bool) $this->option('recursive');

        if ($isSelf) {
            // Determine our own repository URL and install mode
            $repoUrl = $this->workspace->resolveSelfRepositoryUrl($useSsh);
            // Self-cloned toolkit is naturally a dev dependency by default
            $install = true;
            $dev = true;
        } else {
            if ($rawPackage === '') {
                $this->error('Please specify a repository URL/shorthand or pass the [--self] flag.');
                $this->line('  <comment>Usage examples:</comment>');
                $this->line('  <info>php artisan package:clone vendor/package</info>');
                $this->line('  <info>php artisan package:clone git@github.com:vendor/package.git</info>');
                $this->line('  <info>php artisan package:clone --self</info>');

                return self::FAILURE;
            }

            if (str_starts_with($rawPackage, '-')) {
                $this->error("Invalid repository URL [{$rawPackage}]: option-like arguments are not permitted.");

                return self::FAILURE;
            }

            $repoUrl = $this->workspace->normalizeRepositoryUrl($rawPackage, $useSsh);
        }

        if ($dev && ! $install) {
            $this->error('The [--dev] option can only be used in combination with [--install].');
            $this->line('  <comment>How to fix:</comment> Pass [--install] along with [--dev]:');
            $this->line('  <info>php artisan package:clone '.($rawPackage ?: '--self').' --install --dev</info>');

            return self::FAILURE;
        }

        // Resolve workspace
        $rawWorkspace = (string) $this->option('workspace');
        if ($rawWorkspace === '') {
            $workspace = $this->workspace->getDefault();
            if (! $workspace) {
                $this->error('No default workspace is currently configured.');
                $this->line('  <comment>How to fix:</comment> Add a workspace first, or specify one via the [--workspace] option:');
                $this->line('  <info>php artisan workspace:register packages</info>');
                $this->line('  <info>php artisan package:clone '.($rawPackage ?: '--self').' --workspace=packages</info>');

                return self::FAILURE;
            }
        } else {
            $workspace = trim(preg_replace('#[/\\\\]+#', '/', $rawWorkspace) ?? '', '/');
        }

        $workspaces = $this->workspace->all();
        if (! isset($workspaces[$workspace])) {
            $this->error("Workspace [{$workspace}] does not exist.");
            $this->line('  <comment>How to fix:</comment> You can register this workspace first using:');
            $this->line("  <info>php artisan workspace:register {$workspace}</info>");

            return self::FAILURE;
        }

        $workspaceVendor = $this->workspace->getWorkspaceVendor($workspace);
        $alias = trim((string) ($this->option('as') ?: $this->option('alias')));

        if ($alias !== '') {
            if ($workspaceVendor === null) {
                $this->error('Aliases are only supported in flat (fixed-vendor) workspaces.');
                $this->line("  <comment>Notice:</comment> Workspace [{$workspace}] is a nested multi-vendor workspace (e.g. packages/{vendor}/{package}).");

                return self::FAILURE;
            }

            try {
                $this->workspace->validateAliasName($alias);
            } catch (WorkspaceException $e) {
                return $this->handleWorkspaceException($e);
            }
        }

        // Pre-parse vendor and package hints from URL (e.g. vendor/package)
        [$inferredVendor, $inferredPackage] = $this->workspace->parseRepoVendorAndPackage($repoUrl);

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
            $existingComposer = "{$fullTargetPath}/composer.json";
            $existingPackageName = null;
            if (File::exists($existingComposer)) {
                $pkgData = json_decode(File::get($existingComposer), true);
                $existingPackageName = $pkgData['name'] ?? null;
            }

            $displayPkg = $existingPackageName
                ?? ($inferredVendor && $inferredPackage ? "{$inferredVendor}/{$inferredPackage}" : $packageName);

            $this->error("Target directory [{$relativeTargetPath}] already exists.");
            $this->newLine();
            $this->line('  <comment>The package is already present on disk. Available actions:</comment>');
            $this->line("  • Link into Composer:    <info>php artisan package:install {$displayPkg}</info>");
            $this->line("  • Pull latest changes:   <info>git -C {$relativeTargetPath} pull</info>");
            $this->line('  • Re-clone from scratch: Delete or rename the directory first, or run:');
            $this->line("                            <info>php artisan package:delete {$displayPkg}</info>");

            return self::FAILURE;
        }

        $this->info("Cloning [{$repoUrl}] into [{$relativeTargetPath}]...");

        $timeout = (int) config('workspace.process_timeout', 300);

        $diagnostic = $this->cloner->cloneRepository($repoUrl, $fullTargetPath, $timeout);

        if ($diagnostic !== null) {
            $this->error("Failed to clone repository [{$repoUrl}].");
            $this->newLine();
            $this->warn("  [{$diagnostic->title}]");
            $this->line("  {$diagnostic->explanation}");

            if (! empty($diagnostic->actionableSteps)) {
                $this->newLine();
                $this->line('  <fg=yellow>How to fix:</>');
                foreach ($diagnostic->actionableSteps as $step) {
                    $this->line("  • {$step}");
                }
            }

            if ($diagnostic->rawOutput !== null && $diagnostic->rawOutput !== '') {
                $this->newLine();
                $this->line("  <comment>Git output:</comment>\n  ".str_replace("\n", "\n  ", $diagnostic->rawOutput));
            }

            return self::FAILURE;
        }

        // Verify cloned repository has composer.json
        $clonedComposerPath = "{$fullTargetPath}/composer.json";
        if (! File::exists($clonedComposerPath)) {
            $this->warn("Cloned repository does not contain a composer.json file at [{$relativeTargetPath}/composer.json].");
        }

        // Sync workspace registry
        $this->workspace->sync();

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

        $isShorthand = ! $isSelf && preg_match('#^[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+$#', trim($rawPackage));
        $customUrl = ($isShorthand || $isSelf) ? null : $repoUrl;

        // Store package entry with optional custom URL and optional alias in workspace.json
        $this->workspace->recordPackage($workspace, $recordedName, $alias !== '' ? $alias : null, $customUrl);

        $this->info("Repository successfully cloned to [{$relativeTargetPath}].");

        if ($alias !== '') {
            $this->warnIfDuplicateAlias($alias, $relativeTargetPath, $canonicalComposerName ?: $recordedName);
        }

        // Optional symlinking via Composer
        if ($install && $canonicalComposerName) {
            $this->info("Registering and symlinking [{$canonicalComposerName}] into root application...");

            $requireArgs = ['require', "{$canonicalComposerName}:@dev"];
            if ($dev) {
                $requireArgs[] = '--dev';
            }

            try {
                $installResult = $this->composer->runComposer($requireArgs, $timeout);

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

        if ($isSelf) {
            $linked = $this->cloner->linkHostAgentsGuideline($relativeTargetPath);
            if ($linked) {
                $this->info("Linked host [AGENTS.md] to local toolkit stub [{$relativeTargetPath}/stubs/AGENTS.md.stub] for continuous development.");
            }
        }

        // Recursive cloning of dependencies from trusted organizations
        if ($recursive && File::exists($clonedComposerPath)) {
            $rootVendor = null;
            if ($canonicalComposerName && str_contains($canonicalComposerName, '/')) {
                [$rootVendor] = explode('/', $canonicalComposerName, 2);
            } elseif ($inferredVendor) {
                $rootVendor = $inferredVendor;
            } elseif ($workspaceVendor) {
                $rootVendor = $workspaceVendor;
            }

            $visited = [$canonicalComposerName ?? $packageName => true];
            $this->cloner->cloneDependenciesRecursively(
                $clonedComposerPath,
                $workspace,
                $useSsh,
                $install,
                $dev,
                $timeout,
                $visited,
                $rootVendor,
                fn (string $level, string $msg) => match ($level) {
                    'warn' => $this->warn($msg),
                    'line' => $this->line($msg),
                    default => $this->info($msg),
                }
            );
        }

        // Ask to link package into application Composer if running interactively
        if (! $install && $canonicalComposerName && $this->shouldPromptInstall()) {
            $refName = $alias !== '' ? $alias : $canonicalComposerName;
            $this->newLine();
            if ($this->confirm("Would you like to link [{$refName}] into Composer now?", true)) {
                return $this->call('package:install', [
                    'package' => $refName,
                    '--dev' => $dev,
                ]);
            }
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
     * Determine whether the command should interactively prompt to install the package.
     */
    protected function shouldPromptInstall(): bool
    {
        if (! $this->input->isInteractive()) {
            return false;
        }

        if (is_a($this->output, 'Mockery\MockInterface')) {
            $director = $this->output->mockery_getExpectationsFor('askQuestion');

            return $director !== null && ! empty($director->getExpectations());
        }

        if (app()->runningUnitTests()) {
            return false;
        }

        return (function_exists('stream_isatty') && @stream_isatty(STDIN))
            || (function_exists('posix_isatty') && @posix_isatty(STDIN));
    }
}
