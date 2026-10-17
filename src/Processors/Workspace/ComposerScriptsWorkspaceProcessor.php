<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Processors\Workspace;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\WorkspaceContext;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerManager;
use Illuminate\Support\Facades\File;

class ComposerScriptsWorkspaceProcessor extends BaseWorkspaceProcessor
{
    public function __construct(
        protected readonly ComposerManager $composer,
    ) {}

    public function process(WorkspaceContext $context): void
    {
        $composerPath = $context->rootPath.DIRECTORY_SEPARATOR.'composer.json';

        if (! File::exists($composerPath)) {
            $context->recordStep('composer', 'skipped', 'composer.json was not found; restore hooks and routine scripts were not installed.');

            return;
        }

        $before = (string) md5_file($composerPath);

        $this->composer->ensureComposerHooks();

        $after = (string) md5_file($composerPath);

        $context->recordStep(
            'composer',
            $before === $after ? 'skipped' : 'updated',
            $before === $after
                ? 'Restore hooks and routine scripts are already present.'
                : 'Installed restore hooks and workspace:check / workspace:push / workspace:pull scripts.'
        );
    }
}
