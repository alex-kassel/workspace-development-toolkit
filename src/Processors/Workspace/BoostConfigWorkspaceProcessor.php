<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Processors\Workspace;

use AlexKassel\WorkspaceDevelopmentToolkit\Actions\SetupBoostConfigAction;
use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\WorkspaceContext;
use Illuminate\Support\Facades\File;

class BoostConfigWorkspaceProcessor extends BaseWorkspaceProcessor
{
    public function __construct(
        protected readonly SetupBoostConfigAction $action,
    ) {}

    public function process(WorkspaceContext $context): void
    {
        $packageDir = dirname(__DIR__, 3);
        $composerJsonPath = $packageDir.DIRECTORY_SEPARATOR.'composer.json';
        $packages = [];

        if (File::exists($composerJsonPath)) {
            $data = json_decode((string) File::get($composerJsonPath), true);
            if (! empty($data['name'])) {
                $packages[] = (string) $data['name'];
            }
        }

        foreach ($this->action->execute($context->rootPath, $packages, $context->force) as $step) {
            $context->recordStep('boost', $step->status, $step->message);
        }
    }
}
