<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Actions;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\ActionStep;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceManager;
use Generator;

class RegisterPackageInManifestAction extends BaseAction
{
    public function __construct(
        protected readonly WorkspaceManager $workspace,
    ) {}

    /**
     * @return Generator<int, ActionStep>
     */
    public function execute(
        string $workspace,
        string $packageName,
        ?string $alias = null,
    ): Generator {
        $this->workspace->sync();

        if ($alias !== null && $alias !== '') {
            $this->workspace->registerPackageAlias($workspace, $packageName, $alias);
        }

        yield ActionStep::created("Registered [{$packageName}] in workspace [{$workspace}].");
    }
}
