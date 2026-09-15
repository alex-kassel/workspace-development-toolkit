<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Processors\Package;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\PackageContext;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\Platform;
use Illuminate\Support\Facades\File;

class ValidatePackageProcessor extends BasePackageProcessor
{
    public function process(PackageContext $context): void
    {
        $workspaces = Workspace::all();

        if (! array_key_exists($context->workspace, $workspaces)) {
            $available = empty($workspaces) ? 'none' : implode(', ', array_keys($workspaces));
            throw new WorkspaceException(
                "Workspace [{$context->workspace}] is not registered. Available workspaces: [{$available}].",
                "Register the workspace first:\n  php artisan workspace:register {$context->workspace}"
            );
        }

        $workspaceVendor = Workspace::getWorkspaceVendor($context->workspace);

        if ($context->alias !== null && $context->alias !== '' && $workspaceVendor === null) {
            throw new WorkspaceException(
                'Aliases are only supported in flat (fixed-vendor) workspaces.',
                "Workspace [{$context->workspace}] is a nested multi-vendor workspace (e.g. packages/{vendor}/{package})."
            );
        }

        if ($workspaceVendor !== null && strtolower($context->vendor) !== strtolower($workspaceVendor)) {
            throw new WorkspaceException(
                "Workspace [{$context->workspace}] has a fixed vendor [{$workspaceVendor}], but [{$context->vendor}] was provided.",
                "Omit the vendor prefix or match the workspace vendor:\n  php artisan package:make {$context->package} --workspace={$context->workspace}"
            );
        }

        if (File::isDirectory($context->packageFullPath())) {
            $inspectCmd = Platform::inspectDirectoryCommand($context->relativePackagePath);
            $deleteCmd = Platform::deleteDirectoryCommand($context->relativePackagePath);

            if ($context->alias !== null && $context->alias !== '') {
                $errorMessage = "Cannot use alias [{$context->alias}]: target directory [{$context->relativePackagePath}] already exists on disk.";
                $solution = "Choose a different alias name, or remove the conflicting directory manually:\n  {$deleteCmd}";

                throw new WorkspaceException($errorMessage, $solution);
            }

            $isRegisteredPackage = false;
            try {
                $isRegisteredPackage = Workspace::findPackagePath($context->package) !== null
                    || Workspace::findPackagePath($context->fullName()) !== null;
            } catch (\Throwable) {
                $isRegisteredPackage = false;
            }

            if ($isRegisteredPackage) {
                $existingInWorkspace = array_map(
                    fn ($p) => is_array($p) ? ($p['name'] ?? '') : (string) $p,
                    Workspace::all()[$context->workspace]['packages'] ?? []
                );
                $existingList = ! empty($existingInWorkspace) ? "\nExisting packages in [{$context->workspace}]: ".implode(', ', $existingInWorkspace) : '';
                $errorMessage = "Package directory [{$context->relativePackagePath}] already exists on disk.{$existingList}";
                $solution = "Choose a different package name, or permanently delete the existing package:\n  php artisan package:delete {$context->package} --force";
            } else {
                $errorMessage = "Target directory [{$context->relativePackagePath}] already exists on disk and is not a registered workspace package.";
                $solution = "Choose a different package name, or inspect and remove the existing directory manually:\n  • Inspect folder contents: {$inspectCmd}\n  • Delete folder manually:  {$deleteCmd}";
            }

            throw new WorkspaceException($errorMessage, $solution);
        }

        $context->recordStep('validate', 'success', "Validated workspace [{$context->workspace}] and package path [{$context->relativePackagePath}].");
    }
}
