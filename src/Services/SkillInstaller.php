<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
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
     *
     * Smart Single-Target Priority:
     * 1. Explicit configuration (config('workspace.skills_path'))
     * 2. Existing .agents/skills (primary standard for Antigravity, Claude, etc.)
     * 3. Existing .cursor/skills (if user only uses Cursor)
     * 4. Existing .claude/skills (if user only uses Claude Code)
     * 5. Default fallback to .agents/skills
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
     * Check if the skill is already installed at the target location.
     */
    public function isInstalled(?string $targetDir = null): bool
    {
        $target = ($targetDir ?? $this->detectSkillsDirectory()).DIRECTORY_SEPARATOR.'package-docs';

        return file_exists($target.DIRECTORY_SEPARATOR.'SKILL.md');
    }

    /**
     * Install the skill files to the target directory.
     */
    public function install(?string $targetSkillsDir = null, bool $force = false, bool $symlink = false): bool
    {
        $baseSkillsDir = $targetSkillsDir ?? $this->detectSkillsDirectory();
        $targetDir = $baseSkillsDir.DIRECTORY_SEPARATOR.'package-docs';
        $sourceDir = $this->getSourcePath();

        if (file_exists($targetDir)) {
            if (! $force) {
                return false;
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

        $sourceSkill = $sourceDir.DIRECTORY_SEPARATOR.'SKILL.md';
        if (file_exists($sourceSkill)) {
            @copy($sourceSkill, $targetDir.DIRECTORY_SEPARATOR.'SKILL.md');
        }

        $this->copyDirectory(
            $sourceDir.DIRECTORY_SEPARATOR.'references',
            $targetDir.DIRECTORY_SEPARATOR.'references'
        );

        $this->copyDirectory(
            $sourceDir.DIRECTORY_SEPARATOR.'resources',
            $targetDir.DIRECTORY_SEPARATOR.'resources'
        );

        return file_exists($targetDir.DIRECTORY_SEPARATOR.'SKILL.md');
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