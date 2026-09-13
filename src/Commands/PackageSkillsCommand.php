<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerManager;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\SkillInstaller;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceManager;

class PackageSkillsCommand extends BasePackageCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:skills
        {package : Package name in vendor/package format (e.g. acme/my-pkg)}
        {--symlink : Create symlinks instead of copying (for live dev)}
        {--force : Force overwrite existing skills}
        {--remove : Remove all installed skills of this package from project}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage and synchronize agent skills for a specific package';

    public function __construct(
        WorkspaceManager $workspace,
        ComposerManager $composer,
        protected readonly SkillInstaller $installer,
    ) {
        parent::__construct($workspace, $composer);
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $rawPackage = (string) $this->argument('package');
        $symlink = (bool) $this->option('symlink');
        $force = (bool) $this->option('force');
        $remove = (bool) $this->option('remove');

        $package = $this->workspace->resolveCanonicalPackageName($rawPackage);
        $packagePath = $this->workspace->findPackagePath($package);

        if ($packagePath === null) {
            $this->error("Package [{$rawPackage}] not found in any registered workspace.");

            return self::FAILURE;
        }

        $skillsPath = base_path($packagePath.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'skills');
        $discovered = $this->installer->discoverSkillsInPath($skillsPath);

        if (empty($discovered)) {
            $this->comment("No skills discovered in [{$packagePath}/resources/skills].");

            return self::SUCCESS;
        }

        // Find which workspace this package belongs to
        $matchedWorkspace = $this->workspace->findWorkspaceForPath($packagePath);

        if ($remove) {
            $this->info("Removing skills for package [{$package}]...");
            foreach ($discovered as $slug => $path) {
                if ($this->installer->removeSkill($slug)) {
                    $this->line("  ✔ Removed skill [{$slug}]");
                }
            }

            if ($matchedWorkspace !== null) {
                $this->workspace->updatePackageSkills($matchedWorkspace, $package, []);
            }

            $this->info("Skills for [{$package}] successfully removed.");

            return self::SUCCESS;
        }

        $this->info("Synchronizing skills for package [{$package}]:");
        $activeSkills = [];
        $hasErrors = false;

        foreach ($discovered as $slug => $sourceDir) {
            $skillMd = $sourceDir.DIRECTORY_SEPARATOR.'SKILL.md';
            if (! $this->installer->isPublished($skillMd) && ! $force) {
                $this->comment("  ⏭  Skipping draft skill [{$slug}] (status is not 'published')");

                continue;
            }

            try {
                $installed = $this->installer->installSkill(
                    skillSlug: $slug,
                    sourceSkillDir: $sourceDir,
                    force: $force,
                    symlink: $symlink
                );

                if ($installed) {
                    $mode = $symlink ? 'symlinked' : 'copied';
                    $this->line("  ✔ Materialized skill [{$slug}] ({$mode})");
                    $activeSkills[] = $slug;
                }
            } catch (\Throwable $e) {
                $this->error("  ✖ Failed [{$slug}]: {$e->getMessage()}");
                $hasErrors = true;
            }
        }

        if (empty($activeSkills) && ! $hasErrors) {
            $this->comment("No published skills found to install for [{$package}].");
        }

        if ($matchedWorkspace !== null) {
            $this->workspace->updatePackageSkills($matchedWorkspace, $package, $activeSkills);
        }

        return $hasErrors ? self::FAILURE : self::SUCCESS;
    }
}
