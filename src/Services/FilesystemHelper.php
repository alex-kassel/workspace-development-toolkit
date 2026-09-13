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
            clearstatcache(true, $dir);

            return ! is_dir($dir);
        } catch (Throwable) {
            return File::deleteDirectory($dir);
        }
    }

    /**
     * Determine if path is a symlink or directory junction (cross-platform, reliable on Windows).
     */
    public function isLinkOrJunction(string $path): bool
    {
        clearstatcache(true, $path);

        return is_link($path) || @readlink($path) !== false;
    }

    /**
     * Re-point or recreate a symlink or directory junction.
     */
    public function updateSymlinkOrJunction(string $linkPath, string $targetFullPath): void
    {
        File::ensureDirectoryExists(dirname($linkPath));

        if (PHP_OS_FAMILY === 'Windows') {
            if (is_link($linkPath) || file_exists($linkPath) || is_dir($linkPath) || @readlink($linkPath) !== false) {
                $removed = @rmdir($linkPath);
                if (! $removed) {
                    @unlink($linkPath);
                }

                clearstatcache(true, $linkPath);

                if (file_exists($linkPath) || is_dir($linkPath) || @readlink($linkPath) !== false || is_link($linkPath)) {
                    throw new \RuntimeException(
                        "Failed to create directory junction [{$linkPath}] -> [{$targetFullPath}]: target location is occupied by an existing directory or file that could not be removed."
                    );
                }
            }

            $winLink = str_replace('/', '\\', $linkPath);
            $winTarget = str_replace('/', '\\', $targetFullPath);

            $escapeCmdPath = function (string $path): string {
                if (! str_contains($path, '%')) {
                    return '"'.str_replace('"', '""', $path).'"';
                }

                $parts = explode('%', $path);
                $escaped = '';
                foreach ($parts as $i => $part) {
                    if ($i > 0) {
                        $escaped .= '^%';
                    }
                    if ($part !== '') {
                        $escaped .= '"'.str_replace('"', '""', $part).'"';
                    }
                }

                return $escaped;
            };

            $cmd = sprintf('cmd /c mklink /J %s %s', $escapeCmdPath($winLink), $escapeCmdPath($winTarget));
            $result = Process::run($cmd);

            if (! $result->successful()) {
                throw new \RuntimeException(
                    "Failed to create directory junction [{$winLink}] -> [{$winTarget}]: "
                    .trim($result->errorOutput() ?: $result->output())
                );
            }

            clearstatcache(true, $linkPath);

            if (! file_exists($linkPath) && ! $this->isLinkOrJunction($linkPath)) {
                throw new \RuntimeException(
                    "Failed to create directory junction [{$winLink}] -> [{$winTarget}]: link path was not created."
                );
            }
        } else {
            if (is_link($linkPath) || file_exists($linkPath)) {
                if (! @unlink($linkPath)) {
                    throw new \RuntimeException("Failed to remove existing file or symlink at [{$linkPath}].");
                }
            }

            if (! @symlink($targetFullPath, $linkPath)) {
                throw new \RuntimeException("Failed to create symlink [{$linkPath}] -> [{$targetFullPath}].");
            }
        }
    }
}
