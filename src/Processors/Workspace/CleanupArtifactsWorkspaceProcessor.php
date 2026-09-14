<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Processors\Workspace;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\WorkspaceContext;
use Illuminate\Support\Facades\File;

class CleanupArtifactsWorkspaceProcessor extends BaseWorkspaceProcessor
{
    public function process(WorkspaceContext $context): void
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
