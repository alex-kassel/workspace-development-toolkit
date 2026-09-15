<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Processors\Package;

use AlexKassel\WorkspaceDevelopmentToolkit\Actions\RequireLocalPackageAction;
use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\PackageContext;
use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;

class ComposerRequirePackageProcessor extends BasePackageProcessor
{
    public function __construct(
        protected readonly RequireLocalPackageAction $action,
    ) {}

    public function process(PackageContext $context): void
    {
        if (! $context->install) {
            $context->recordStep('composer', 'skipped', 'Automatic Composer installation skipped (not requested).');

            return;
        }

        $workspaces = Workspace::all();

        try {
            foreach ($this->action->execute($context->fullName(), $context->dev, $workspaces) as $step) {
                $context->recordStep('composer', $step->status, $step->message);
            }
        } catch (\Throwable $e) {
            $context->recordStep('composer', 'failed', "Automatic Composer installation failed: {$e->getMessage()}");
        }
    }
}
