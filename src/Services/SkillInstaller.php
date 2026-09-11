<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;

class SkillInstaller
{
    public function __construct(
        private readonly ?string $defaultSourcePath = null
    ) {}

    /**
     * Get the default source directory of the toolkit skill files.
     */
    public function getDefaultSourcePath(): string
    {
        return $this->defaultSourcePath ?? dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'skills';
    }

    /**
     * Detect the single most appropriate skills directory for the host project.
     */
    public function detectSkillsDirectory(): string
    {
        $configured = config('workspace.skills_path');
        if (is_string($configured) && $configured !== '') {
            return base_path($configured);
        }

        if (is_dir(base_path('.agents/skills'))) {
            return base_path('.agents/skills');
        }

        if (is_dir(base_path('.cursor/skills'))) {
            return base_path('.cursor/skills');
        }

        if (is_dir(base_path('.claude/skills'))) {
            return base_path('.claude/skills');
        }

        return base_path('.agents/skills');
    }

    /**
     * Parse YAML frontmatter from a SKILL.md file.
     *
     * @return array<string, mixed>
     */
    public function parseSkillFrontmatter(string $skillFilePath): array
    {
        if (! file_exists($skillFilePath)) {
            return [];
        }

        $content = (string) file_get_contents($skillFilePath);
        if (preg_match('/^---\s*[\r\n]+(.*?)\s*[\r\n]+---/s', $content, $matches)) {
            try {
                $parsed = Yaml::parse($matches[1]);

                return is_array($parsed) ? $parsed : [];
            } catch (\Throwable) {
                return [];
            }
        }

        return [];
    }

    /**
     * Parse the origin metadata from a SKILL.md file.
     */
    public function readSkillOrigin(string $skillFilePath): ?string
    {
        $metadata = $this->parseSkillFrontmatter($skillFilePath);
        $origin = $metadata['origin'] ?? null;

        return is_string($origin) && trim($origin) !== '' ? trim($origin) : null;
    }

    /**
     * Check if a skill file is officially published and ready for distribution.
     */
    public function isPublished(string $skillFilePath): bool
    {
        $metadata = $this->parseSkillFrontmatter($skillFilePath);

        return ($metadata['status'] ?? null) === 'published';
    }

    /**
     * Discover all skill directories within a given package's resources/skills path.
     *
     * @return array<string, string> Map of skill-slug => absolute-skill-path
     */
    public function discoverSkillsInPath(string $skillsBasePath): array
    {
        if (! is_dir($skillsBasePath)) {
            return [];
        }

        $skills = [];
        $iterator = new RecursiveDirectoryIterator($skillsBasePath, RecursiveDirectoryIterator::SKIP_DOTS);

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isDir() && file_exists($item->getPathname().DIRECTORY_SEPARATOR.'SKILL.md')) {
                $skills[$item->getFilename()] = $item->getPathname();
            }
        }

        return $skills;
    }

    /**
     * Check if a skill is already installed and matches expected origin.
     */
    public function isInstalled(string $skillSlug, string $sourceSkillDir, ?string $targetSkillsDir = null): bool
    {
        $baseSkillsDir = $targetSkillsDir ?? $this->detectSkillsDirectory();
        $targetSkillMd = $baseSkillsDir.DIRECTORY_SEPARATOR.$skillSlug.DIRECTORY_SEPARATOR.'SKILL.md';
        $sourceSkillMd = $sourceSkillDir.DIRECTORY_SEPARATOR.'SKILL.md';

        if (! file_exists($targetSkillMd)) {
            return false;
        }

        $expectedOrigin = $this->readSkillOrigin($sourceSkillMd);
        $installedOrigin = $this->readSkillOrigin($targetSkillMd);

        return $installedOrigin !== null && $installedOrigin === $expectedOrigin;
    }

    /**
     * Install a single skill directory to target location.
     */
    public function installSkill(
        string $skillSlug,
        string $sourceSkillDir,
        ?string $targetSkillsDir = null,
        bool $force = false,
        bool $symlink = false
    ): bool {
        $sourceSkillMd = $sourceSkillDir.DIRECTORY_SEPARATOR.'SKILL.md';
        if (! file_exists($sourceSkillMd)) {
            return false;
        }

        if (! $this->isPublished($sourceSkillMd) && ! $force) {
            return false; // Skip draft skills
        }

        $baseSkillsDir = $targetSkillsDir ?? $this->detectSkillsDirectory();
        $targetDir = $baseSkillsDir.DIRECTORY_SEPARATOR.$skillSlug;
        $targetSkillMd = $targetDir.DIRECTORY_SEPARATOR.'SKILL.md';
        $expectedOrigin = $this->readSkillOrigin($sourceSkillMd);

        if (file_exists($targetDir)) {
            $installedOrigin = $this->readSkillOrigin($targetSkillMd);

            if ($installedOrigin !== null && $installedOrigin !== $expectedOrigin && ! $force) {
                throw new RuntimeException(
                    "Skill collision detected! A skill named '{$skillSlug}' is already installed from '{$installedOrigin}'. "
                    ."Expected origin: '{$expectedOrigin}'. Configure an alias or use --force to overwrite."
                );
            }

            if (! $force && $installedOrigin === $expectedOrigin) {
                return true;
            }

            $this->deleteDirectory($targetDir);
        }

        if (! is_dir($baseSkillsDir) && ! @mkdir($baseSkillsDir, 0755, true) && ! is_dir($baseSkillsDir)) {
            return false;
        }

        if ($symlink) {
            if (@symlink($sourceSkillDir, $targetDir)) {
                return true;
            }
        }

        if (! is_dir($targetDir) && ! @mkdir($targetDir, 0755, true) && ! is_dir($targetDir)) {
            return false;
        }

        @copy($sourceSkillMd, $targetSkillMd);

        $referencesDir = $sourceSkillDir.DIRECTORY_SEPARATOR.'references';
        if (is_dir($referencesDir)) {
            $this->copyDirectory($referencesDir, $targetDir.DIRECTORY_SEPARATOR.'references');
        }

        $resourcesDir = $sourceSkillDir.DIRECTORY_SEPARATOR.'resources';
        if (is_dir($resourcesDir)) {
            $this->copyDirectory($resourcesDir, $targetDir.DIRECTORY_SEPARATOR.'resources');
        }

        return file_exists($targetSkillMd);
    }

    /**
     * Remove an installed skill by slug.
     */
    public function removeSkill(string $skillSlug, ?string $targetSkillsDir = null): bool
    {
        $baseSkillsDir = $targetSkillsDir ?? $this->detectSkillsDirectory();
        $targetDir = $baseSkillsDir.DIRECTORY_SEPARATOR.$skillSlug;

        if (! file_exists($targetDir)) {
            return false;
        }

        $this->deleteDirectory($targetDir);

        return ! file_exists($targetDir);
    }

    /**
     * Copy directory recursively.
     */
    private function copyDirectory(string $source, string $destination): void
    {
        if (! is_dir($source)) {
            return;
        }

        if (! is_dir($destination)) {
            @mkdir($destination, 0755, true);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            $target = $destination.DIRECTORY_SEPARATOR.$iterator->getSubPathname();
            if ($item->isDir()) {
                if (! is_dir($target)) {
                    @mkdir($target, 0755, true);
                }
            } else {
                @copy($item->getPathname(), $target);
            }
        }
    }

    /**
     * Delete directory recursively.
     */
    private function deleteDirectory(string $dir): void
    {
        if (is_link($dir)) {
            @unlink($dir);

            return;
        }

        if (! is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isDir() && ! $item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }
}
