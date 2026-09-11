<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

class SkillInstaller
{
    public function __construct(
        private readonly ?string $sourcePath = null
    ) {}

    /**
     * Get the source directory of the skill files.
     */
    public function getSourcePath(): string
    {
        return $this->sourcePath ?? dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'skills'.DIRECTORY_SEPARATOR.'package-docs';
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
     * Parse the origin metadata from a SKILL.md file.
     */
    public function readSkillOrigin(string $skillFilePath): ?string
    {
        if (! file_exists($skillFilePath)) {
            return null;
        }

        $content = (string) file_get_contents($skillFilePath);
        if (preg_match('/^---\s*[\r\n]+(.*?)\s*[\r\n]+---/s', $content, $matches)) {
            if (preg_match('/^origin:\s*(.+)$/m', $matches[1], $originMatches)) {
                return trim($originMatches[1], " \t\n\r\0\x0B\"'");
            }
        }

        return null;
    }

    /**
     * Check if a skill file is marked as draft.
     */
    public function isDraft(string $skillFilePath): bool
    {
        if (! file_exists($skillFilePath)) {
            return false;
        }

        $content = (string) file_get_contents($skillFilePath);
        if (preg_match('/^---\s*[\r\n]+(.*?)\s*[\r\n]+---/s', $content, $matches)) {
            if (preg_match('/^status:\s*(draft|disabled)$/mi', $matches[1])) {
                return true;
            }
            if (preg_match('/^draft:\s*(true|1|yes)$/mi', $matches[1])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the skill is already installed and belongs to this package.
     */
    public function isInstalled(?string $targetSkillsDir = null, string $skillSlug = 'package-docs'): bool
    {
        $baseSkillsDir = $targetSkillsDir ?? $this->detectSkillsDirectory();
        $targetSkillMd = $baseSkillsDir.DIRECTORY_SEPARATOR.$skillSlug.DIRECTORY_SEPARATOR.'SKILL.md';
        $sourceSkillMd = $this->getSourcePath().DIRECTORY_SEPARATOR.'SKILL.md';

        if (! file_exists($targetSkillMd)) {
            return false;
        }

        $expectedOrigin = $this->readSkillOrigin($sourceSkillMd) ?? 'alex-kassel/workspace-development-toolkit';
        $installedOrigin = $this->readSkillOrigin($targetSkillMd);

        return $installedOrigin === $expectedOrigin;
    }

    /**
     * Install the skill files to the target directory with origin collision detection.
     */
    public function install(?string $targetSkillsDir = null, bool $force = false, bool $symlink = false, string $skillSlug = 'package-docs'): bool
    {
        $baseSkillsDir = $targetSkillsDir ?? $this->detectSkillsDirectory();
        $targetDir = $baseSkillsDir.DIRECTORY_SEPARATOR.$skillSlug;
        $sourceDir = $this->getSourcePath();
        $sourceSkillMd = $sourceDir.DIRECTORY_SEPARATOR.'SKILL.md';
        $targetSkillMd = $targetDir.DIRECTORY_SEPARATOR.'SKILL.md';

        $expectedOrigin = $this->readSkillOrigin($sourceSkillMd) ?? 'alex-kassel/workspace-development-toolkit';

        // Draft skills must never be automatically materialized into active agent skills
        if ($this->isDraft($sourceSkillMd) && ! $force) {
            return false;
        }

        if (file_exists($targetDir)) {
            $installedOrigin = $this->readSkillOrigin($targetSkillMd);

            // If it belongs to a different origin and not forcing, raise collision exception
            if ($installedOrigin !== null && $installedOrigin !== $expectedOrigin && ! $force) {
                throw new RuntimeException(
                    "Skill collision detected! A skill named '{$skillSlug}' is already installed from '{$installedOrigin}'. "
                    ."Expected origin: '{$expectedOrigin}'. Configure an alias or use --force to overwrite."
                );
            }

            if (! $force && $installedOrigin === $expectedOrigin) {
                return true; // Already installed and up-to-date
            }

            $this->deleteDirectory($targetDir);
        }

        if (! is_dir($baseSkillsDir) && ! @mkdir($baseSkillsDir, 0755, true) && ! is_dir($baseSkillsDir)) {
            return false;
        }

        if ($symlink) {
            if (@symlink($sourceDir, $targetDir)) {
                return true;
            }
        }

        if (! is_dir($targetDir) && ! @mkdir($targetDir, 0755, true) && ! is_dir($targetDir)) {
            return false;
        }

        if (file_exists($sourceSkillMd)) {
            @copy($sourceSkillMd, $targetSkillMd);
        }

        $this->copyDirectory(
            $sourceDir.DIRECTORY_SEPARATOR.'references',
            $targetDir.DIRECTORY_SEPARATOR.'references'
        );

        $this->copyDirectory(
            $sourceDir.DIRECTORY_SEPARATOR.'resources',
            $targetDir.DIRECTORY_SEPARATOR.'resources'
        );

        return file_exists($targetSkillMd);
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