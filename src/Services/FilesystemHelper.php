<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use FilesystemIterator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

class FilesystemHelper
{
    /**
     * Recursively delete a directory, unsetting read-only flags (essential for Windows .git pack files).
     */
    public function deleteDirectoryRecursively(string $dir): bool
    {
        if (! File::isDirectory($dir)) {
            return true;
        }

        try {
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($items as $item) {
                // Use pathname directly so symlinks are not followed to their real path targets
                $itemPath = $item->getPathname();

                if ($item->isLink() || is_link($itemPath)) {
                    @chmod($itemPath, 0755);
                    if (PHP_OS_FAMILY === 'Windows' && is_dir($itemPath)) {
                        @rmdir($itemPath);
                    } else {
                        @unlink($itemPath);
                    }

                    continue;
                }

                if ($item->isDir()) {
                    @chmod($itemPath, 0755);
                    @rmdir($itemPath);
                } else {
                    @chmod($itemPath, 0644);
                    @unlink($itemPath);
                }
            }

            @chmod($dir, 0755);
            @rmdir($dir);

            return ! File::isDirectory($dir);
        } catch (Throwable) {
            return File::deleteDirectory($dir);
        }
    }

    /**
     * Re-point or recreate a symlink or directory junction.
     */
    public function updateSymlinkOrJunction(string $linkPath, string $targetFullPath): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            if (is_link($linkPath) || file_exists($linkPath) || is_dir($linkPath)) {
                @rmdir($linkPath);
                $winLink = str_replace('/', '\\', $linkPath);
                $winTarget = str_replace('/', '\\', $targetFullPath);
                Process::run(['cmd', '/c', 'mklink', '/J', $winLink, $winTarget]);
            }
        } else {
            if (is_link($linkPath) || file_exists($linkPath)) {
                @unlink($linkPath);
                @symlink($targetFullPath, $linkPath);
            }
        }
    }
}
