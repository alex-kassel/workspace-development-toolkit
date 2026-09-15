<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Processors\Workspace;

use AlexKassel\WorkspaceDevelopmentToolkit\Actions\RunCustomHookAction;
use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\WorkspaceContext;

class CustomHookWorkspaceProcessor extends BaseWorkspaceProcessor
{
    public function __construct(
        protected readonly RunCustomHookAction $action,
    ) {}

    public function process(WorkspaceContext $context): void
    {
        $customHookConfig = config('workspace.post_install_hook');
        $candidatePaths = array_values(array_filter([
            $customHookConfig ? (string) $customHookConfig : null,
            $context->rootPath.DIRECTORY_SEPARATOR.'stubs'.DIRECTORY_SEPARATOR.'workspace'.DIRECTORY_SEPARATOR.'hooks'.DIRECTORY_SEPARATOR.'post-install.php',
            $context->rootPath.DIRECTORY_SEPARATOR.'.workspace-post-install.php',
        ]));

        foreach ($this->action->execute($context->rootPath, $candidatePaths, ['context' => $context]) as $step) {
            $context->recordStep('hook', $step->status, $step->message);
        }
    }
}
