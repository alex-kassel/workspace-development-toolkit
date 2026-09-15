<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Processors\Workspace;

use AlexKassel\WorkspaceDevelopmentToolkit\Actions\SetupWorkspaceManifestAction;
use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\WorkspaceContext;

class SetupManifestWorkspaceProcessor extends BaseWorkspaceProcessor
{
    public function __construct(
        protected readonly SetupWorkspaceManifestAction $action,
    ) {}

    public function process(WorkspaceContext $context): void
    {
        foreach ($this->action->execute($context->rootPath, $context->workspaces, $context->defaultWorkspace, $context->force) as $step) {
            $context->recordStep('manifest', $step->status, $step->message);
        }
    }
}
