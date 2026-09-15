<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Processors\Package;

use AlexKassel\WorkspaceDevelopmentToolkit\Actions\InitializePackageGitAction;
use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\PackageContext;

class InitializeGitPackageProcessor extends BasePackageProcessor
{
    public function __construct(
        protected readonly InitializePackageGitAction $action,
    ) {}

    public function process(PackageContext $context): void
    {
        foreach ($this->action->execute($context->packageFullPath(), $context->fullName(), 'v0.0.1') as $step) {
            $context->recordStep('git', $step->status, $step->message);
        }
    }
}
