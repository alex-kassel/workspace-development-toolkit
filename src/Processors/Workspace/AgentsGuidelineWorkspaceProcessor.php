<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Processors\Workspace;

use AlexKassel\WorkspaceDevelopmentToolkit\Actions\SetupAgentsGuidelineAction;
use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\WorkspaceContext;

class AgentsGuidelineWorkspaceProcessor extends BaseWorkspaceProcessor
{
    public function __construct(
        protected readonly SetupAgentsGuidelineAction $action,
    ) {}

    public function process(WorkspaceContext $context): void
    {
        foreach ($this->action->execute($context->rootPath, $context->isSelf, $context->selfPackagePath, $context->force) as $step) {
            $context->recordStep('guidelines', $step->status, $step->message);
        }
    }
}
