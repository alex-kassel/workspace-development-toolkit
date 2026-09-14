<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Processors\Workspace;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\WorkspaceContext;
use Illuminate\Support\Facades\File;

class RunnerWorkspaceProcessor extends BaseWorkspaceProcessor
{
    public function process(WorkspaceContext $context): bool
    {
        $runnerPath = $context->runnerPath();
        $stubPath = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'stubs'.DIRECTORY_SEPARATOR.'workspace.stub';

        if (! File::exists($stubPath)) {
            $context->recordStep('runner', 'failed', "workspace.stub not found at [{$stubPath}].");

            return false;
        }

        if (File::exists($runnerPath) && ! $context->force) {
            $context->recordStep('runner', 'skipped', "Workspace CLI runner already exists at [{$runnerPath}].");

            return true;
        }

        File::copy($stubPath, $runnerPath);
        @chmod($runnerPath, 0755);

        $context->recordStep('runner', 'created', 'Published standalone workspace runner to ./workspace.');

        return true;
    }
}
