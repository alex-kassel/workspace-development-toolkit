<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Processors\Workspace;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\WorkspaceContext;
use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use Illuminate\Support\Facades\File;

class SetupManifestWorkspaceProcessor extends BaseWorkspaceProcessor
{
    public function process(WorkspaceContext $context): void
    {
        $manifestPath = $context->manifestPath();
        $cleanWorkspace = trim(str_replace(['\\', '/'], '/', $context->defaultWorkspace), '/');

        if (File::exists($manifestPath) && ! $context->force) {
            $context->recordStep('manifest', 'skipped', "workspace.json already exists at [{$manifestPath}].");

            return;
        }

        File::ensureDirectoryExists($context->workspacePath($cleanWorkspace));

        $initialData = [
            'default' => $cleanWorkspace,
            'workspaces' => [
                $cleanWorkspace => [
                    'vendor' => null,
                    'packages' => [],
                ],
            ],
        ];

        File::put($manifestPath, json_encode($initialData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        Workspace::clearCache();

        $context->recordStep('manifest', 'created', "Initialized workspace.json with default workspace [{$cleanWorkspace}].");
    }
}
