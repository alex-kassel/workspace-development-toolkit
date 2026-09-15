<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Actions;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\ActionStep;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\StubResolver;
use Generator;
use Illuminate\Support\Facades\File;

class ScaffoldPackageStructureAction extends BaseAction
{
    public function __construct(
        protected readonly StubResolver $stubResolver,
    ) {}

    /**
     * @param  array<string, string>  $replacements
     * @return Generator<int, ActionStep>
     */
    public function execute(
        string $packageFullPath,
        string $workspace,
        array $replacements,
        ?string $archetype = null,
        bool $scaffoldSkills = true,
    ): Generator {
        File::ensureDirectoryExists($packageFullPath);

        $resolution = $this->stubResolver->resolve($workspace, $archetype);
        $finalReplacements = array_merge($replacements, $resolution->extraReplacements);

        $renderedCount = 0;
        foreach ($resolution->fileMap as $targetRelPath => $fullStubPath) {
            if (! $scaffoldSkills && str_starts_with($targetRelPath, 'resources/boost/skills')) {
                continue;
            }

            $content = (string) File::get($fullStubPath);
            $renderedContent = str_replace(array_keys($finalReplacements), array_values($finalReplacements), $content);
            $renderedRelPath = str_replace(array_keys($finalReplacements), array_values($finalReplacements), $targetRelPath);

            $targetFullPath = $packageFullPath.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $renderedRelPath);
            File::ensureDirectoryExists(dirname($targetFullPath));
            File::put($targetFullPath, rtrim($renderedContent)."\n");
            $renderedCount++;
        }

        $archetypeDesc = $archetype !== null && trim($archetype) !== '' ? " [{$archetype}]" : '';
        yield ActionStep::created("Scaffolded package files{$archetypeDesc} ({$renderedCount} files).");
    }
}
