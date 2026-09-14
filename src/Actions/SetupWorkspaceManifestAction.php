<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Actions;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\InstallContext;
use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use Closure;
use Illuminate\Support\Facades\File;

class SetupWorkspaceManifestAction
{
    public function handle(InstallContext $context, Closure $next): mixed
    {
        $this->execute($context);

        return $next($context);
    }

    public function execute(InstallContext $context): void
    {
        $manifestPath = $context->rootPath.DIRECTORY_SEPARATOR.'workspace.json';
        $cleanWorkspace = trim(str_replace(['\\', '/'], '/', $context->defaultWorkspace), '/');

        if (File::exists($manifestPath) && ! $context->force) {
            $context->recordStep('manifest', 'skipped', "workspace.json already exists at [{$manifestPath}].");

            return;
        }

        File::ensureDirectoryExists($context->rootPath.DIRECTORY_SEPARATOR.$cleanWorkspace);

        $initialData = [
            'workspaces' => [
                $cleanWorkspace => [
                    'vendor' => null,
                    'is_default' => true,
                    'packages' => [],
                ],
            ],
        ];

        File::put($manifestPath, json_encode($initialData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        Workspace::clearCache();

        $context->recordStep('manifest', 'created', "Initialized workspace.json with default workspace [{$cleanWorkspace}].");
    }
}
