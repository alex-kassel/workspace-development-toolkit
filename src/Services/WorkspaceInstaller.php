<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\Actions\CleanupHostArtifactsAction;
use AlexKassel\WorkspaceDevelopmentToolkit\Actions\PublishWorkspaceRunnerAction;
use AlexKassel\WorkspaceDevelopmentToolkit\Actions\RunCustomHookAction;
use AlexKassel\WorkspaceDevelopmentToolkit\Actions\SetupAgentsGuidelineAction;
use AlexKassel\WorkspaceDevelopmentToolkit\Actions\SetupBoostConfigAction;
use AlexKassel\WorkspaceDevelopmentToolkit\Actions\SetupWorkspaceManifestAction;
use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\InstallContext;
use Illuminate\Pipeline\Pipeline;

class WorkspaceInstaller
{
    /**
     * @var array<int, class-string>
     */
    protected array $defaultPipes = [
        SetupWorkspaceManifestAction::class,
        SetupAgentsGuidelineAction::class,
        SetupBoostConfigAction::class,
        PublishWorkspaceRunnerAction::class,
        CleanupHostArtifactsAction::class,
        RunCustomHookAction::class,
    ];

    public function __construct(
        protected Pipeline $pipeline,
    ) {}

    /**
     * Run the installation pipeline for the given context.
     *
     * @param  array<int, class-string>|null  $pipes
     */
    public function install(InstallContext $context, ?array $pipes = null): InstallContext
    {
        /** @var InstallContext */
        return $this->pipeline
            ->send($context)
            ->through($pipes ?? $this->defaultPipes)
            ->then(fn (InstallContext $ctx): InstallContext => $ctx);
    }
}
