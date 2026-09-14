<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Actions;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\InstallContext;
use Closure;
use Illuminate\Support\Facades\File;

class SetupAgentsGuidelineAction
{
    public function handle(InstallContext $context, Closure $next): mixed
    {
        $this->execute($context);

        return $next($context);
    }

    public function execute(InstallContext $context): bool
    {
        $hostAgentsPath = $context->rootPath.DIRECTORY_SEPARATOR.'AGENTS.md';
        $bundledStubPath = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'stubs'.DIRECTORY_SEPARATOR.'AGENTS.md.stub';

        if ($context->isSelf && $context->selfPackagePath !== null) {
            $relPkgPath = trim(str_replace(['\\', '/'], '/', $context->selfPackagePath), '/');
            $stubFullPath = $context->rootPath.DIRECTORY_SEPARATOR.$relPkgPath.DIRECTORY_SEPARATOR.'stubs'.DIRECTORY_SEPARATOR.'AGENTS.md.stub';

            if (! File::exists($stubFullPath)) {
                $context->recordStep('guidelines', 'failed', "Stub not found at [{$stubFullPath}].");

                return false;
            }

            $relativeLinkTarget = $relPkgPath.'/stubs/AGENTS.md.stub';

            if (is_link($hostAgentsPath)) {
                $currentTarget = (string) @readlink($hostAgentsPath);
                if ($currentTarget === $relativeLinkTarget || $currentTarget === $stubFullPath) {
                    $context->recordStep('guidelines', 'skipped', "AGENTS.md is already linked to [{$relativeLinkTarget}].");

                    return true;
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
                $context->recordStep('guidelines', 'linked', "Symlinked AGENTS.md to [{$relativeLinkTarget}].");

                return true;
            }

            $context->recordStep('guidelines', 'failed', "Failed to create symlink for AGENTS.md.");

            return false;
        }

        // Standard standalone installation
        if (File::exists($hostAgentsPath) && ! $context->force) {
            $context->recordStep('guidelines', 'skipped', "AGENTS.md already exists at [{$hostAgentsPath}].");

            return true;
        }

        if (File::exists($bundledStubPath)) {
            File::copy($bundledStubPath, $hostAgentsPath);
            $context->recordStep('guidelines', 'created', 'Created AGENTS.md from bundled guideline stub.');

            return true;
        }

        $context->recordStep('guidelines', 'failed', "Bundled AGENTS.md.stub not found at [{$bundledStubPath}].");

        return false;
    }
}
