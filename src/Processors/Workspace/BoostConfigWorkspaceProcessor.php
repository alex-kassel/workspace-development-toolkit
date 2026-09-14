<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Processors\Workspace;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\WorkspaceContext;
use Illuminate\Support\Facades\File;

class BoostConfigWorkspaceProcessor extends BaseWorkspaceProcessor
{
    public function process(WorkspaceContext $context): bool
    {
        $boostJsonPath = $context->boostJsonPath();
        $bundledStub = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'stubs'.DIRECTORY_SEPARATOR.'boost.json.stub';

        if (! File::exists($boostJsonPath)) {
            if (File::exists($bundledStub)) {
                File::copy($bundledStub, $boostJsonPath);
                $context->recordStep('boost', 'created', 'Created boost.json with guidelines protection and package registration.');

                return true;
            }

            $defaultConfig = [
                'packages' => ['alex-kassel/workspace-development-toolkit'],
                'guidelines' => false,
            ];
            File::put($boostJsonPath, json_encode($defaultConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            $context->recordStep('boost', 'created', 'Created boost.json with guidelines protection.');

            return true;
        }

        $content = json_decode((string) File::get($boostJsonPath), true);
        if (! is_array($content)) {
            $context->recordStep('boost', 'failed', "Unable to parse [{$boostJsonPath}].");

            return false;
        }

        $modified = false;
        if (($content['guidelines'] ?? true) !== false) {
            $content['guidelines'] = false;
            $modified = true;
        }

        $packages = (array) ($content['packages'] ?? []);
        if (! in_array('alex-kassel/workspace-development-toolkit', $packages, true)) {
            $packages[] = 'alex-kassel/workspace-development-toolkit';
            $content['packages'] = array_values($packages);
            $modified = true;
        }

        if ($modified || $context->force) {
            File::put($boostJsonPath, json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            $context->recordStep('boost', 'updated', 'Configured existing boost.json to protect guidelines and register toolkit.');

            return true;
        }

        $context->recordStep('boost', 'skipped', 'boost.json is already properly configured.');

        return true;
    }
}
