<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Actions;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\InstallContext;
use Closure;
use Illuminate\Support\Facades\File;

class PublishWorkspaceRunnerAction
{
    public function handle(InstallContext $context, Closure $next): mixed
    {
        $this->execute($context);

        return $next($context);
    }

    public function execute(InstallContext $context): bool
    {
        $runnerPath = $context->rootPath.DIRECTORY_SEPARATOR.'workspace';
        $stubPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'stubs'.DIRECTORY_SEPARATOR.'workspace.stub';

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
