<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\DTOs;

readonly class StubResolutionResult
{
    /**
     * @param  array<string, string>  $fileMap  [targetRelativePath => absoluteStubSourcePath]
     * @param  array<string, string>  $extraReplacements  [placeholder => replacement]
     * @param  array<int, string>  $excludedFiles  Target relative paths that were suppressed
     * @param  array<int, string>  $appliedTiers  Names/paths of tiers applied
     */
    public function __construct(
        public array $fileMap,
        public array $extraReplacements = [],
        public array $excludedFiles = [],
        public ?string $archetype = null,
        public array $appliedTiers = [],
    ) {}
}
