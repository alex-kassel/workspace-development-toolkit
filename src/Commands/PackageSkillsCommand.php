<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\SkillInstaller;
use Illuminate\Console\Command;

class PackageSkillsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:skills
        {name : Package name or alias}
        {--symlink : Create symlinks instead of copying (for live dev)}
        {--force : Force overwrite existing skills}
        {--remove : Remove all installed skills of this package from project}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage and synchronize agent skills for a specific package';

    /**
     * Execute the console command.
     */
    public function handle(SkillInstaller $installer): int
    {
        $rawName = (string) $this->argument('name');
        $symlink = (bool) $this->option('symlink');
        $force = (bool) $this->option('force');
        $remove = (bool) $this->option('remove');

        $canonicalName = Workspace::resolveCanonicalPackageName($rawName);
        $packagePath = Workspace::findPackagePath($canonicalName);

        if ($packagePath === null) {
            $this->error("Package [{$rawName}] not found in any registered workspace.");

            return self::FAILURE;
        }

        $skillsPath = base_path($packagePath.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'skills');
        $discovered = $installer->discoverSkillsInPath($skillsPath);

        if (empty($discovered)) {
            $this->comment("No skills discovered in [{$packagePath}/resources/skills].");

            return self::SUCCESS;
        }

        // Find which workspace this package belongs to
        $workspace = null;
        foreach (Workspace::all() as $wsPath => $wsConfig) {
            $wsPathStr = (string) $wsPath;
            if (str_starts_with($packagePath, $wsPathStr.'/')) {
                $workspace = $wsPathStr;
                break;
            }
        }

        if ($remove) {
            $this->info("Removing skills for package [{$canonicalName}]...");
            foreach ($discovered as $slug => $path) {
                if ($installer->removeSkill($slug)) {
                    $this->line("  ✔ Removed skill [{$slug}]");
                }
            }

            if ($workspace !== null) {
                Workspace::updatePackageSkills($workspace, $canonicalName, []);
            }

            $this->info("Skills for [{$canonicalName}] successfully removed.");

            return self::SUCCESS;
        }

        $this->info("Synchronizing skills for package [{$canonicalName}]:");
        $activeSkills = [];
        $hasErrors = false;

        foreach ($discovered as $slug => $sourceDir) {
            $skillMd = $sourceDir.DIRECTORY_SEPARATOR.'SKILL.md';
            if (! $installer->isPublished($skillMd) && ! $force) {
                $this->comment("  ⏭  Skipping draft skill [{$slug}] (status is not 'published')");

                continue;
            }

            try {
                $installed = $installer->installSkill(
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
            $this->comment("No published skills found to install for [{$canonicalName}].");
        }

        if ($workspace !== null) {
            Workspace::updatePackageSkills($workspace, $canonicalName, $activeSkills);
        }

        return $hasErrors ? self::FAILURE : self::SUCCESS;
    }
}
