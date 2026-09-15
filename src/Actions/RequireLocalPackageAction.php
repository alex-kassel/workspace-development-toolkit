<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Actions;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\ActionStep;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerManager;
use Generator;

class RequireLocalPackageAction extends BaseAction
{
    public function __construct(
        protected readonly ComposerManager $composer,
    ) {}

    /**
     * @param  array<string, mixed>  $workspaces
     * @return Generator<int, ActionStep>
     */
    public function execute(string $package, bool $isDev, array $workspaces): Generator
    {
        $this->composer->syncRepositories($workspaces);

        $args = ['require', "{$package}:@dev"];
        if ($isDev) {
            $args[] = '--dev';
        }

        $this->composer->runComposer($args);

        $devDesc = $isDev ? ' (dev)' : '';
        yield ActionStep::created("Installed [{$package}]{$devDesc} via Composer.");
    }
}
