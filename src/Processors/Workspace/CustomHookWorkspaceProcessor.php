<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Processors\Workspace;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\WorkspaceContext;
use Illuminate\Support\Facades\File;

class CustomHookWorkspaceProcessor extends BaseWorkspaceProcessor
{
    public function process(WorkspaceContext $context): void
    {
        $customHookConfig = config('workspace.post_install_hook');
        $candidatePaths = [
            $customHookConfig ? (string) $customHookConfig : null,
            $context->rootPath.DIRECTORY_SEPARATOR.'stubs'.DIRECTORY_SEPARATOR.'workspace'.DIRECTORY_SEPARATOR.'hooks'.DIRECTORY_SEPARATOR.'post-install.php',
            $context->rootPath.DIRECTORY_SEPARATOR.'.workspace-post-install.php',
        ];

        foreach (array_filter($candidatePaths) as $candidate) {
            if (File::exists($candidate)) {
                $rel = trim(str_replace($context->rootPath, '', $candidate), DIRECTORY_SEPARATOR);

                try {
                    (static function (WorkspaceContext $context, string $file): void {
                        require $file;
                    })($context, $candidate);

                    $context->recordStep('hook', 'executed', "Executed custom post-install hook from [{$rel}].");
                } catch (\Throwable $e) {
                    $context->recordStep('hook', 'failed', "Post-install hook [{$rel}] failed: {$e->getMessage()}");
                }

                return;
            }
        }

        $context->recordStep('hook', 'skipped', 'No custom post-install hook found.');
    }
}
