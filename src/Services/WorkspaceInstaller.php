<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\WorkspaceContext;
use AlexKassel\WorkspaceDevelopmentToolkit\Processors\Workspace\AgentsGuidelineWorkspaceProcessor;
use AlexKassel\WorkspaceDevelopmentToolkit\Processors\Workspace\BoostConfigWorkspaceProcessor;
use AlexKassel\WorkspaceDevelopmentToolkit\Processors\Workspace\CleanupArtifactsWorkspaceProcessor;
use AlexKassel\WorkspaceDevelopmentToolkit\Processors\Workspace\CustomHookWorkspaceProcessor;
use AlexKassel\WorkspaceDevelopmentToolkit\Processors\Workspace\RunnerWorkspaceProcessor;
use AlexKassel\WorkspaceDevelopmentToolkit\Processors\Workspace\SetupManifestWorkspaceProcessor;
use Illuminate\Pipeline\Pipeline;

class WorkspaceInstaller
{
    /**
     * @var array<int, class-string>
     */
    protected array $defaultProcessors = [
        SetupManifestWorkspaceProcessor::class,
        AgentsGuidelineWorkspaceProcessor::class,
        BoostConfigWorkspaceProcessor::class,
        RunnerWorkspaceProcessor::class,
        CleanupArtifactsWorkspaceProcessor::class,
        CustomHookWorkspaceProcessor::class,
    ];

    public function __construct(
        protected Pipeline $pipeline,
    ) {}

    /**
     * Run the installation pipeline for the given context.
     *
     * @param  array<int, class-string>|null  $processors
     */
    public function install(WorkspaceContext $context, ?array $processors = null): WorkspaceContext
    {
        /** @var WorkspaceContext */
        return $this->pipeline
            ->send($context)
            ->through($processors ?? $this->defaultProcessors)
            ->then(fn (WorkspaceContext $ctx): WorkspaceContext => $ctx);
    }
}
