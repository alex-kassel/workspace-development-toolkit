<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Processors\Workspace;

use AlexKassel\WorkspaceDevelopmentToolkit\Actions\CleanupHostArtifactsAction;
use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\WorkspaceContext;

class CleanupArtifactsWorkspaceProcessor extends BaseWorkspaceProcessor
{
    public function __construct(
        protected readonly CleanupHostArtifactsAction $action,
    ) {}

    public function process(WorkspaceContext $context): void
    {
        $filesToClean = (array) config('workspace.cleanup_files', ['CLOUD.md']);

        foreach ($this->action->execute($context->rootPath, $context->skipCleanup, $filesToClean) as $step) {
            $context->recordStep('cleanup', $step->status, $step->message);
        }
    }
}
