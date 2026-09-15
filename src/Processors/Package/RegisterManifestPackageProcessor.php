<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Processors\Package;

use AlexKassel\WorkspaceDevelopmentToolkit\Actions\RegisterPackageInManifestAction;
use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\PackageContext;
use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;

class RegisterManifestPackageProcessor extends BasePackageProcessor
{
    public function __construct(
        protected readonly RegisterPackageInManifestAction $action,
    ) {}

    public function process(PackageContext $context): void
    {
        $workspaceVendor = Workspace::getWorkspaceVendor($context->workspace);
        $packageName = $workspaceVendor !== null ? $context->package : $context->fullName();

        foreach ($this->action->execute(
            $context->workspace,
            $packageName,
            $context->alias,
        ) as $step) {
            $context->recordStep('manifest', $step->status, $step->message);
        }
    }
}
