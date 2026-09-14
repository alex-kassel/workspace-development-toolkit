<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Actions;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\InstallContext;
use Closure;
use Illuminate\Support\Facades\File;

class CleanupHostArtifactsAction
{
    public function handle(InstallContext $context, Closure $next): mixed
    {
        $this->execute($context);

        return $next($context);
    }

    public function execute(InstallContext $context): void
    {
        if ($context->skipCleanup) {
            $context->recordStep('cleanup', 'skipped', 'Host artifact cleanup skipped by flag.');

            return;
        }

        $filesToClean = (array) config('workspace.cleanup_files', ['CLOUD.md']);
        $cleaned = [];

        foreach ($filesToClean as $file) {
            $target = $context->rootPath.DIRECTORY_SEPARATOR.trim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, (string) $file), DIRECTORY_SEPARATOR);

            if (File::exists($target)) {
                File::delete($target);
                $cleaned[] = (string) $file;
            }
        }

        if (! empty($cleaned)) {
            $fileList = implode(', ', $cleaned);
            $context->recordStep('cleanup', 'cleaned', "Removed redundant host artifact(s): [{$fileList}].");
        } else {
            $context->recordStep('cleanup', 'skipped', 'No redundant host artifacts found.');
        }
    }
}
