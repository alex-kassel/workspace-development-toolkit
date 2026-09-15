<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Actions;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\ActionStep;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\GitInspector;
use Generator;

class InitializePackageGitAction extends BaseAction
{
    public function __construct(
        protected readonly GitInspector $gitInspector,
    ) {}

    /**
     * @return Generator<int, ActionStep>
     */
    public function execute(string $packageFullPath, string $packageName, string $initialTag = 'v0.0.1'): Generator
    {
        $this->gitInspector->initializeRepository($packageFullPath, $packageName, $initialTag);

        yield ActionStep::created("Initialized Git repository with tag [{$initialTag}].");
    }
}
