<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Actions;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\ActionStep;
use Generator;
use Illuminate\Support\Facades\File;

class PublishWorkspaceRunnerAction extends BaseAction
{
    /**
     * @return Generator<int, ActionStep>
     */
    public function execute(string $rootPath, bool $force = false): Generator
    {
        $runnerPath = $rootPath.DIRECTORY_SEPARATOR.'workspace';
        $stubPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'stubs'.DIRECTORY_SEPARATOR.'workspace.stub';

        if (! File::exists($stubPath)) {
            yield ActionStep::failed("workspace.stub not found at [{$stubPath}].");

            return;
        }

        if (File::exists($runnerPath) && ! $force) {
            yield ActionStep::skipped("Workspace CLI runner already exists at [{$runnerPath}].");

            return;
        }

        File::copy($stubPath, $runnerPath);
        @chmod($runnerPath, 0755);

        yield ActionStep::created('Published standalone workspace runner to ./workspace.');
    }
}
