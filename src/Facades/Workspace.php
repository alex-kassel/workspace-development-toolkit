<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Facades;

use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static string workspaceJsonPath()
 * @method static string composerJsonPath()
 * @method static array load()
 * @method static array all()
 * @method static ?string getWorkspaceVendor(string $workspace)
 * @method static ?string findPackagePath(string $packageName)
 * @method static string resolveCanonicalPackageName(string $packageName, ?string $workspace = null)
 * @method static string getRepositoryTemplate()
 * @method static void setRepositoryTemplate(string $template)
 * @method static string defaultRepositoryTemplate()
 * @method static string resolvePackageCloneUrl(string $packageName)
 * @method static ?string getDefault()
 * @method static bool setDefault(string $path)
 * @method static void add(string $path, ?string $vendor = null, bool $isDefault = false)
 * @method static bool remove(string $path)
 * @method static array sync()
 * @method static void clearCache()
 * @method static void ensureWorkspaceScript()
 * @method static void ensureComposerHooks()
 * @method static bool deleteDirectoryRecursively(string $dir)
 * @method static array aliasPackage(string $packageName, string $alias)
 * @method static array findDuplicateAliases(string $alias, ?string $excludePath = null)
 * @method static void registerPackageAlias(string $workspace, string $packageName, string $alias)
 * @method static void updateComposerPathReferences(string $canonicalName, string $oldRelPath, string $newRelPath)
 * @method static void save(array $data)
 *
 * @see WorkspaceManager
 */
class Workspace extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return WorkspaceManager::class;
    }
}
