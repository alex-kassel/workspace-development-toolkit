<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

class Platform
{
    /**
     * Determine if the current environment is running on Windows.
     */
    public static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    /**
     * Determine if the current environment is running on macOS.
     */
    public static function isMac(): bool
    {
        return PHP_OS_FAMILY === 'Darwin';
    }

    /**
     * Determine if the current environment is running on Linux.
     */
    public static function isLinux(): bool
    {
        return PHP_OS_FAMILY === 'Linux';
    }

    /**
     * Generate an OS-specific CLI command to inspect a directory's contents.
     */
    public static function inspectDirectoryCommand(string $path): string
    {
        if (self::isWindows()) {
            $winPath = str_replace('/', '\\', $path);

            return "dir \"{$winPath}\"";
        }

        return "ls -la \"{$path}\"";
    }

    /**
     * Generate an OS-specific CLI command to recursively delete a directory.
     */
    public static function deleteDirectoryCommand(string $path): string
    {
        if (self::isWindows()) {
            $winPath = str_replace('/', '\\', $path);

            return "rmdir /s /q \"{$winPath}\"";
        }

        return "rm -rf \"{$path}\"";
    }
}
