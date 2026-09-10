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
 * @method static ?string getDefault()
 * @method static bool setDefault(string $path)
 * @method static void add(string $path, ?string $vendor = null, bool $isDefault = false)
 * @method static bool remove(string $path)
 * @method static array sync()
 * @method static array scanPackages(string $workspace, ?string $vendor = null)
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
