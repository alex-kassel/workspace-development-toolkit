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
        if ($context->skipCleanup) {
            $context->recordStep('cleanup', 'skipped', 'Artifact cleanup skipped by flag.');

            return;
        }

        $filesToClean = (array) config('workspace.cleanup_files', ['CLOUD.md', '.cloud']);

        foreach ($this->action->execute($context->rootPath, $filesToClean) as $step) {
            $context->recordStep('cleanup', $step->status, $step->message);
        }
    }
}
