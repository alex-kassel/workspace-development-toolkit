<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Processors\Package;

use AlexKassel\WorkspaceDevelopmentToolkit\Actions\ScaffoldPackageStructureAction;
use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\PackageContext;
use Illuminate\Support\Str;

class ScaffoldStructurePackageProcessor extends BasePackageProcessor
{
    public function __construct(
        protected readonly ScaffoldPackageStructureAction $action,
    ) {}

    public function process(PackageContext $context): void
    {
        $frameworkVersion = app()->version();
        $currentMajor = 11;
        if (preg_match('/^(\d+)/', $frameworkVersion, $matches)) {
            $currentMajor = max(11, (int) $matches[1]);
        }
        $supportedMajors = [];
        for ($v = 11; $v <= $currentMajor; $v++) {
            $supportedMajors[] = "^{$v}.0";
        }
        $illuminateConstraint = implode('|', $supportedMajors);

        $primarySkill = $context->skills[0] ?? Str::kebab(str_replace('/', '-', $context->fullName()));

        $replacements = [
            '{{ vendor }}' => $context->vendor,
            '{{ package }}' => $context->package,
            '{{ vendorNamespace }}' => $context->vendorNamespace(),
            '{{ packageNamespace }}' => $context->packageNamespace(),
            '{{ providerClass }}' => $context->providerClass(),
            '{{ year }}' => date('Y'),
            '{{ illuminate_constraint }}' => $illuminateConstraint,
            '{{ skillSlug }}' => $primarySkill,
        ];

        foreach ($this->action->execute(
            $context->packageFullPath(),
            $context->workspace,
            $replacements,
            $context->archetype,
            $context->scaffoldSkills,
        ) as $step) {
            $context->recordStep('scaffold', $step->status, $step->message);
        }
    }
}
