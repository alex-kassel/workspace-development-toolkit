<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Actions;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\ActionStep;
use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use Generator;
use Illuminate\Support\Facades\File;

class SetupWorkspaceManifestAction extends BaseAction
{
    /**
     * @param  array<int, string>  $workspaces
     * @return Generator<int, ActionStep>
     */
    public function execute(
        string $rootPath,
        array $workspaces,
        ?string $defaultWorkspace = null,
        bool $force = false,
    ): Generator {
        $manifestPath = $rootPath.DIRECTORY_SEPARATOR.'workspace.json';

        if (File::exists($manifestPath) && ! $force) {
            yield ActionStep::skipped("workspace.json already exists at [{$manifestPath}].");

            return;
        }

        $workspacesMap = [];
        foreach ($workspaces as $ws) {
            $clean = trim(str_replace(['\\', '/'], '/', $ws), '/');
            if ($clean === '') {
                continue;
            }

            $fullDir = $rootPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $clean);
            File::ensureDirectoryExists($fullDir);

            $workspacesMap[$clean] = [
                'vendor' => null,
                'packages' => [],
            ];
        }

        $cleanDefault = null;
        if ($defaultWorkspace !== null && trim($defaultWorkspace) !== '') {
            $cleanDefault = trim(str_replace(['\\', '/'], '/', $defaultWorkspace), '/');
        } elseif (count($workspacesMap) > 0) {
            $cleanDefault = (string) array_key_first($workspacesMap);
        }

        $initialData = [
            'default' => $cleanDefault,
            'workspaces' => $workspacesMap,
        ];

        File::put($manifestPath, json_encode($initialData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        Workspace::clearCache();

        if ($cleanDefault !== null) {
            yield ActionStep::created("Initialized workspace.json with default workspace [{$cleanDefault}].");
            foreach (array_keys($workspacesMap) as $registeredWs) {
                if ($registeredWs !== $cleanDefault) {
                    yield ActionStep::created("Created workspace directory [{$registeredWs}].");
                }
            }
        } else {
            yield ActionStep::created('Initialized workspace.json with zero workspaces.');
        }
    }
}
