<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Support\Chaos;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use Illuminate\Support\Facades\File;

final class SabotageEngine
{
    /**
     * Corrupt workspace.json with completely invalid JSON syntax.
     */
    public static function corruptWorkspaceJson(string $basePath): void
    {
        $manifestPath = rtrim($basePath, '/\\').DIRECTORY_SEPARATOR.'workspace.json';
        File::put($manifestPath, "{\n  \"invalid\": json syntax with unclosed quotes...\n");
        touch($manifestPath, time() + 5);
        clearstatcache(true, $manifestPath);

        if (class_exists(Workspace::class)) {
            Workspace::clearCache();
        }
    }

    /**
     * Delete a package directly from disk behind the toolkit's back.
     */
    public static function deletePackageBehindBack(string $packageFullPath): void
    {
        if (File::isDirectory($packageFullPath)) {
            File::deleteDirectory($packageFullPath);
        }
    }

    /**
     * Corrupt or truncate composer.json inside a package.
     */
    public static function corruptPackageComposerJson(string $packageFullPath): void
    {
        $composerPath = rtrim($packageFullPath, '/\\').DIRECTORY_SEPARATOR.'composer.json';
        File::put($composerPath, '{ "name": "broken/json", invalid syntax');
    }

    /**
     * Delete composer.json inside a package while leaving source code intact.
     */
    public static function removePackageComposerJson(string $packageFullPath): void
    {
        $composerPath = rtrim($packageFullPath, '/\\').DIRECTORY_SEPARATOR.'composer.json';
        if (File::exists($composerPath)) {
            File::delete($composerPath);
        }
    }

    /**
     * Inject an alien unmanaged directory into a workspace folder with arbitrary files.
     */
    public static function injectAlienDirectory(string $workspaceFullPath, string $folderName = 'alien-dir'): string
    {
        $target = rtrim($workspaceFullPath, '/\\').DIRECTORY_SEPARATOR.$folderName;
        File::ensureDirectoryExists($target);
        File::put($target.DIRECTORY_SEPARATOR.'alien_file.txt', 'This file was created by an alien process.');
        File::put($target.DIRECTORY_SEPARATOR.'config.yaml', 'unrelated: true');

        return $target;
    }

    /**
     * Inject untracked dirty files into a package Git repository.
     */
    public static function injectUntrackedGitFiles(string $packageFullPath): void
    {
        if (File::isDirectory($packageFullPath.'/.git')) {
            File::put($packageFullPath.'/DIRTY_UNTRACKED.tmp', 'untracked content');
        }
    }

    /**
     * Inject uncommitted modified files into a package Git repository.
     */
    public static function injectUncommittedGitChanges(string $packageFullPath): void
    {
        $targetFile = $packageFullPath.'/README.md';
        if (File::exists($targetFile)) {
            File::append($targetFile, "\nUncommitted sabotage edit.\n");
        }
    }
}
