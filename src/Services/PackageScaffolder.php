<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use FilesystemIterator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class PackageScaffolder
{
    public function __construct(
        public readonly GitInspector $gitInspector,
        public readonly SkillInstaller $skillInstaller,
    ) {}

    /**
     * Scaffold a new package in the given workspace.
     *
     * @return array{
     *     name: string,
     *     package: string,
     *     vendorName: string,
     *     packageName: string,
     *     shortName: string,
     *     packagePath: string,
     *     displayPath: string,
     *     alias: ?string,
     *     workspace: string
     * }
     *
     * @throws WorkspaceException
     */
    public function scaffold(
        string $workspace,
        string $rawPackage,
        ?string $alias = null,
        bool $scaffoldSkills = true,
        ?string $skillSlug = null
    ): array {
        // 1. Mandatory Git preflight verification
        $this->ensureGitConfigured();

        // 2. Validate workspace existence
        $cleanWorkspace = trim(preg_replace('#[/\\\\]+#', '/', $workspace) ?? '', '/');
        $workspaces = Workspace::all();

        if (! array_key_exists($cleanWorkspace, $workspaces)) {
            $available = empty($workspaces) ? 'none' : implode(', ', array_keys($workspaces));
            throw new WorkspaceException(
                "Workspace [{$cleanWorkspace}] is not registered. Available workspaces: [{$available}].",
                "Register the workspace first:\n  php artisan workspace:add {$cleanWorkspace}"
            );
        }

        $workspaceVendor = Workspace::getWorkspaceVendor($cleanWorkspace);
        $cleanAlias = $alias !== null ? trim($alias) : '';

        if ($cleanAlias !== '' && $workspaceVendor === null) {
            throw new WorkspaceException(
                'Aliases are only supported in flat (fixed-vendor) workspaces.',
                "Workspace [{$cleanWorkspace}] is a nested multi-vendor workspace (e.g. packages/{vendor}/{package})."
            );
        }

        if ($cleanAlias !== '' && ! preg_match('/^[a-zA-Z0-9_.-]+$/', $cleanAlias)) {
            throw new WorkspaceException(
                "Invalid alias [{$cleanAlias}].",
                'Alias must contain only alphanumeric characters, dashes, underscores, and dots.'
            );
        }

        $normalizedInput = str_replace('\\', '/', trim($rawPackage));
        $validation = Workspace::validatePackageName($normalizedInput, $workspaceVendor);

        if ($workspaceVendor !== null) {
            // Flat 1-level workspace: vendor is fixed
            if (str_contains($normalizedInput, '/')) {
                [$providedVendor] = explode('/', $normalizedInput, 2);
                if (strtolower(trim($providedVendor)) !== strtolower($workspaceVendor)) {
                    throw new WorkspaceException(
                        "Workspace [{$cleanWorkspace}] has a fixed vendor [{$workspaceVendor}], but [{$providedVendor}] was provided.",
                        "Omit the vendor prefix or match the workspace vendor:\n  php artisan package:make {$validation['package']} --workspace={$cleanWorkspace}"
                    );
                }
            }

            if (! $validation['isValid']) {
                $suggestion = $validation['suggestion'] !== null
                    ? "\n  Did you mean: php artisan package:make {$validation['suggestion']} --workspace={$cleanWorkspace}"
                    : '';
                throw new WorkspaceException($validation['error'] ?? "Invalid package name [{$rawPackage}].", $suggestion);
            }

            $vendorName = $validation['vendorName'];
            $packageName = $validation['packageName'];
            $package = $validation['fullName'];
            $shortName = $packageName;
            $dirName = $cleanAlias !== '' ? $cleanAlias : $packageName;
            $packagePath = base_path("{$cleanWorkspace}/{$dirName}");
        } else {
            // Nested 2-level workspace: vendor is required
            if (! str_contains($normalizedInput, '/')) {
                throw new WorkspaceException(
                    "Vendor prefix is required for nested workspace [{$cleanWorkspace}] (e.g. acme/{$rawPackage}).",
                    "Specify the vendor prefix:\n  php artisan package:make acme/{$rawPackage} --workspace={$cleanWorkspace}"
                );
            }

            if (! $validation['isValid']) {
                $suggestion = $validation['suggestion'] !== null
                    ? "\n  Did you mean: php artisan package:make {$validation['suggestion']} --workspace={$cleanWorkspace}"
                    : '';
                throw new WorkspaceException($validation['error'] ?? "Invalid package name [{$rawPackage}].", $suggestion);
            }

            $vendorName = $validation['vendorName'];
            $packageName = $validation['packageName'];
            $package = $validation['fullName'];
            $shortName = $package;
            $packagePath = base_path("{$cleanWorkspace}/{$vendorName}/{$packageName}");
        }

        $relDisplayPath = trim(str_replace(base_path(), '', $packagePath), '/\\');

        if (File::isDirectory($packagePath)) {
            $errorMessage = $cleanAlias !== ''
                ? "Cannot use alias [{$cleanAlias}]: target directory [{$relDisplayPath}] already exists on disk."
                : "Package directory [{$relDisplayPath}] already exists on disk.";

            $solution = $cleanAlias !== ''
                ? 'Choose a different alias name, or permanently delete the existing package:'
                : 'Choose a different package name, or permanently delete the existing package:';

            throw new WorkspaceException(
                $errorMessage,
                "{$solution}\n  php artisan package:delete {$shortName} --force"
            );
        }

        // 3. Prepare replacement tokens
        $vendorNamespace = Str::studly(str_replace(['.', '-'], '_', $vendorName));
        $packageNamespace = Str::studly(str_replace(['.', '-'], '_', $packageName));
        $providerClass = "{$packageNamespace}ServiceProvider";
        $illuminateConstraint = $this->resolveIlluminateConstraint();

        $replacements = [
            '{{ vendor }}' => $vendorName,
            '{{ package }}' => $packageName,
            '{{ vendorNamespace }}' => $vendorNamespace,
            '{{ packageNamespace }}' => $packageNamespace,
            '{{ providerClass }}' => $providerClass,
            '{{ year }}' => date('Y'),
            '{{ illuminate_constraint }}' => $illuminateConstraint,
        ];

        // Snapshot host files before first mutation
        $trackedHostFiles = [
            'workspace.json',
            'composer.json',
            'composer.lock',
            'workspace',
            '.gitignore',
            'vendor/composer/installed.json',
            'vendor/composer/installed.php',
        ];
        $hostSnapshot = [];
        foreach ($trackedHostFiles as $rel) {
            $abs = base_path($rel);
            $hostSnapshot[$abs] = File::exists($abs) ? File::get($abs) : null;
        }

        try {
            // 4. Render all stubs dynamically into the package directory
            $this->renderStubsIntoPackage($packagePath, $replacements, $providerClass, $package);

            // 5. Scaffold agent skill if requested
            if ($scaffoldSkills) {
                $slug = ($skillSlug !== null && trim($skillSlug) !== '') ? trim($skillSlug) : Str::kebab($package);
                $this->scaffoldPackageSkill($packagePath, $slug, $package);
            }

            // 6. Synchronize workspace manifest
            Workspace::sync();

            if ($cleanAlias !== '') {
                Workspace::registerPackageAlias($cleanWorkspace, $shortName, $cleanAlias);
            }

            // 7. Initialize Git repository with initial commit and tag v0.0.1
            $this->gitInspector->initializeRepository($packagePath, $package, 'v0.0.1');
        } catch (\Throwable $e) {
            $cleanupSuccess = true;
            if (File::exists($packagePath)) {
                $cleanupSuccess = app(FilesystemHelper::class)->deleteDirectoryRecursively($packagePath);
                clearstatcache(true, $packagePath);
                if (file_exists($packagePath) || is_dir($packagePath)) {
                    $cleanupSuccess = false;
                }
            }

            // Restore all host files to their exact pre-mutation state
            foreach ($hostSnapshot as $abs => $content) {
                if ($content === null) {
                    if (File::exists($abs)) {
                        @unlink($abs);
                    }
                } else {
                    File::put($abs, $content);
                }
            }

            Workspace::clearCache();

            if (! $cleanupSuccess) {
                throw new WorkspaceException(
                    "Failed to scaffold package [{$package}]: {$e->getMessage()}. Rollback failed: package directory [{$packagePath}] could not be completely removed.",
                    "Inspect and clean up [{$packagePath}] manually.",
                    0,
                    $e
                );
            }

            throw new WorkspaceException(
                "Failed to scaffold package [{$package}]: {$e->getMessage()}. Package creation rolled back.",
                "Ensure prerequisites are met, then retry: php artisan package:make {$package}",
                0,
                $e
            );
        }

        $displayPath = trim(str_replace(base_path(), '', $packagePath), '/\\');

        return [
            'name' => $package,
            'package' => $package,
            'vendorName' => $vendorName,
            'packageName' => $packageName,
            'shortName' => $shortName,
            'packagePath' => $packagePath,
            'displayPath' => $displayPath,
            'alias' => $cleanAlias !== '' ? $cleanAlias : null,
            'workspace' => $cleanWorkspace,
        ];
    }

    /**
     * Ensure Git is installed and global identity is configured.
     *
     * @throws WorkspaceException
     */
    protected function ensureGitConfigured(): void
    {
        $errors = $this->gitInspector->getGitConfigurationErrors();
        if (! empty($errors)) {
            $message = "Git preflight check failed:\n  • ".implode("\n  • ", $errors);
            $solution = "Every package must have Git. Please configure Git globally before creating packages:\n"
                .'  git config --global user.name "Your Name"'."\n"
                .'  git config --global user.email "your.email@example.com"';

            throw new WorkspaceException($message, $solution);
        }
    }

    /**
     * Resolve Illuminate support version constraints based on current framework.
     */
    protected function resolveIlluminateConstraint(): string
    {
        $frameworkVersion = app()->version();
        $currentMajor = 11;
        if (preg_match('/^(\d+)/', $frameworkVersion, $matches)) {
            $currentMajor = max(11, (int) $matches[1]);
        }
        $supportedMajors = [];
        for ($v = 11; $v <= $currentMajor; $v++) {
            $supportedMajors[] = "^{$v}.0";
        }

        return implode('|', $supportedMajors);
    }

    /**
     * Render all stubs (custom and default) into the target package.
     *
     * @param  array<string, string>  $replacements
     */
    protected function renderStubsIntoPackage(
        string $packagePath,
        array $replacements,
        string $providerClass,
        string $package
    ): void {
        File::ensureDirectoryExists($packagePath);

        $defaultStubsDir = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'stubs'.DIRECTORY_SEPARATOR.'package';
        $customStubsDir = base_path('stubs'.DIRECTORY_SEPARATOR.'workspace');

        // Known canonical relative mappings for root stubs
        $standardMappings = [
            'composer.json.stub' => 'composer.json',
            'LICENSE.stub' => 'LICENSE',
            'README.md.stub' => 'README.md',
            'CHANGELOG.md.stub' => 'CHANGELOG.md',
            'gitattributes.stub' => '.gitattributes',
            '.gitattributes.stub' => '.gitattributes',
            'gitignore.stub' => '.gitignore',
            '.gitignore.stub' => '.gitignore',
            'phpunit.xml.stub' => 'phpunit.xml',
            'phpstan.neon.stub' => 'phpstan.neon',
            'TestCase.php.stub' => 'tests/TestCase.php',
            'bootstrap.php.stub' => 'tests/bootstrap.php',
            'ExampleTest.php.stub' => 'tests/Unit/ExampleTest.php',
            'ServiceProvider.php.stub' => "src/{$providerClass}.php",
            'config.php.stub' => "config/{$package}.php",
            'gitkeep.stub' => 'tests/Unit/.gitkeep',
        ];

        // 1. Gather all stub files from default stubs
        $stubFiles = [];
        if (File::isDirectory($defaultStubsDir)) {
            $stubFiles = $this->scanStubFiles($defaultStubsDir);
        }

        // 2. Overlay custom stub files if published
        if (File::isDirectory($customStubsDir)) {
            $customFiles = $this->scanStubFiles($customStubsDir);
            $stubFiles = array_merge($stubFiles, $customFiles);
        }

        foreach ($stubFiles as $relStubPath => $fullStubPath) {
            $content = File::get($fullStubPath);
            $renderedContent = str_replace(array_keys($replacements), array_values($replacements), $content);

            // Determine target relative path
            if (isset($standardMappings[$relStubPath])) {
                $targetRelPath = $standardMappings[$relStubPath];
            } else {
                // Strip .stub extension
                $cleaned = preg_replace('/\.stub$/i', '', $relStubPath) ?? $relStubPath;
                $targetRelPath = str_replace(array_keys($replacements), array_values($replacements), $cleaned);
            }

            $targetFullPath = $packagePath.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $targetRelPath);
            File::ensureDirectoryExists(dirname($targetFullPath));
            File::put($targetFullPath, rtrim($renderedContent)."\n");
        }
    }

    /**
     * Scan a directory recursively for all .stub files.
     *
     * @return array<string, string> Map of relative-stub-path => absolute-file-path
     */
    protected function scanStubFiles(string $dir): array
    {
        $stubs = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isFile() && str_ends_with($item->getFilename(), '.stub')) {
                $relPath = trim(str_replace([$dir, '\\'], ['', '/'], $item->getPathname()), '/');
                $stubs[$relPath] = $item->getPathname();
            }
        }

        return $stubs;
    }

    /**
     * Scaffold initial agent skill in draft status.
     */
    protected function scaffoldPackageSkill(string $packagePath, string $skillSlug, string $packageName): void
    {
        $skillsDir = "{$packagePath}/resources/skills/{$skillSlug}";
        File::ensureDirectoryExists($skillsDir);

        $skillContent = <<<MARKDOWN
---
name: {$skillSlug}
origin: {$packageName}
version: 0.0.1
status: draft
description: >-
  TODO: Operational agent skill for {$packageName} package.
---

# {$skillSlug} Skill

> [!NOTE]
> This skill is currently in **draft** status.
> Fill in instructions and workflows for AI agents, then change `status: draft` to `status: published` to activate publishing.

---

## Operational Workflow

### Phase 0: Tooling Verification & Bootstrapping
Before performing actions with this package:
1. Verify if the package service or commands are available:
   ```bash
   composer show {$packageName}
   ```
2. **If installed**: Proceed to next phase.
3. **If missing**:
   - Check your environment execution policy:
     - If authorized to install dependencies autonomously:
       ```bash
       composer require {$packageName}
       ```
     - Otherwise, request human confirmation before modifying dependencies:
       *"The package [{$packageName}] is required for this operation. May I install it via composer require?"*

MARKDOWN;

        File::put("{$skillsDir}/SKILL.md", $skillContent);
    }
}
