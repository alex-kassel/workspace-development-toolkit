<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Actions;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\ActionStep;
use Generator;
use Illuminate\Support\Facades\File;

class SetupAgentsGuidelineAction extends BaseAction
{
    /**
     * @return Generator<int, ActionStep>
     */
    public function execute(
        string $rootPath,
        bool $isSelf = false,
        ?string $selfPackagePath = null,
        bool $force = false,
    ): Generator {
        $hostAgentsPath = $rootPath.DIRECTORY_SEPARATOR.'AGENTS.md';
        $bundledStubPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'stubs'.DIRECTORY_SEPARATOR.'AGENTS.md.stub';

        if ($isSelf && $selfPackagePath !== null) {
            $relPkgPath = trim(str_replace(['\\', '/'], '/', $selfPackagePath), '/');
            $stubFullPath = $rootPath.DIRECTORY_SEPARATOR.$relPkgPath.DIRECTORY_SEPARATOR.'stubs'.DIRECTORY_SEPARATOR.'AGENTS.md.stub';

            if (! File::exists($stubFullPath)) {
                yield ActionStep::failed("Stub not found at [{$stubFullPath}].");

                return;
            }

            $relativeLinkTarget = $relPkgPath.'/stubs/AGENTS.md.stub';

            if (is_link($hostAgentsPath)) {
                $currentTarget = (string) @readlink($hostAgentsPath);
                if ($currentTarget === $relativeLinkTarget || $currentTarget === $stubFullPath) {
                    yield ActionStep::skipped("AGENTS.md is already linked to [{$relativeLinkTarget}].");

                    return;
                }
                @unlink($hostAgentsPath);
            } elseif (File::exists($hostAgentsPath)) {
                $hostContent = (string) File::get($hostAgentsPath);
                if (trim($hostContent) !== '' && $hostContent !== File::get($stubFullPath)) {
                    File::put($stubFullPath, $hostContent);
                }
                @unlink($hostAgentsPath);
            }

            $linked = @symlink($relativeLinkTarget, $hostAgentsPath);
            if ($linked) {
                yield ActionStep::linked("Symlinked AGENTS.md to [{$relativeLinkTarget}].");

                return;
            }

            yield ActionStep::failed('Failed to create symlink for AGENTS.md.');

            return;
        }

        // Standard standalone installation
        if (File::exists($hostAgentsPath) && ! $force) {
            yield ActionStep::skipped("AGENTS.md already exists at [{$hostAgentsPath}].");

            return;
        }

        if (File::exists($bundledStubPath)) {
            File::copy($bundledStubPath, $hostAgentsPath);
            yield ActionStep::created('Created AGENTS.md from bundled guideline stub.');

            return;
        }

        yield ActionStep::failed("Bundled AGENTS.md.stub not found at [{$bundledStubPath}].");
    }
}
