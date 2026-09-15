<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\PackageContext;
use AlexKassel\WorkspaceDevelopmentToolkit\Processors\Package\ComposerRequirePackageProcessor;
use AlexKassel\WorkspaceDevelopmentToolkit\Processors\Package\InitializeGitPackageProcessor;
use AlexKassel\WorkspaceDevelopmentToolkit\Processors\Package\RegisterManifestPackageProcessor;
use AlexKassel\WorkspaceDevelopmentToolkit\Processors\Package\ScaffoldStructurePackageProcessor;
use AlexKassel\WorkspaceDevelopmentToolkit\Processors\Package\ValidatePackageProcessor;
use Illuminate\Pipeline\Pipeline;

class PackageMaker
{
    /**
     * Default sequence of processors for creating a package.
     *
     * @var array<int, class-string>
     */
    protected array $defaultProcessors = [
        ValidatePackageProcessor::class,
        ScaffoldStructurePackageProcessor::class,
        InitializeGitPackageProcessor::class,
        RegisterManifestPackageProcessor::class,
        ComposerRequirePackageProcessor::class,
    ];

    public function __construct(
        protected readonly Pipeline $pipeline,
    ) {}

    /**
     * Run the package creation pipeline for the given context.
     *
     * @param  array<int, class-string>|null  $processors
     */
    public function make(PackageContext $context, ?array $processors = null): PackageContext
    {
        /** @var PackageContext */
        return $this->pipeline
            ->send($context)
            ->through($processors ?? $this->defaultProcessors)
            ->then(fn (PackageContext $ctx): PackageContext => $ctx);
    }
}
