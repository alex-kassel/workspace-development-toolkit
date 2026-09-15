<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Processors\Workspace;

use AlexKassel\WorkspaceDevelopmentToolkit\Actions\SetupBoostConfigAction;
use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\WorkspaceContext;

class BoostConfigWorkspaceProcessor extends BaseWorkspaceProcessor
{
    public function __construct(
        protected readonly SetupBoostConfigAction $action,
    ) {}

    public function process(WorkspaceContext $context): void
    {
        foreach ($this->action->execute($context->rootPath, ['alex-kassel/workspace-development-toolkit'], $context->force) as $step) {
            $context->recordStep('boost', $step->status, $step->message);
        }
    }
}
