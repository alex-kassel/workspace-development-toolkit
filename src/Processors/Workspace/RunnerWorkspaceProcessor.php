<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Processors\Workspace;

use AlexKassel\WorkspaceDevelopmentToolkit\Actions\PublishWorkspaceRunnerAction;
use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\WorkspaceContext;

class RunnerWorkspaceProcessor extends BaseWorkspaceProcessor
{
    public function __construct(
        protected readonly PublishWorkspaceRunnerAction $action,
    ) {}

    public function process(WorkspaceContext $context): void
    {
        foreach ($this->action->execute($context->rootPath, $context->force) as $step) {
            $context->recordStep('runner', $step->status, $step->message);
        }
    }
}
