<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Exceptions;

class PackageNotFoundException extends WorkspaceException
{
    /**
     * @param  array<int, string>  $searchedWorkspaces
     */
    public function __construct(
        public readonly string $packageName,
        public readonly array $searchedWorkspaces = [],
    ) {
        $workspacesStr = empty($searchedWorkspaces) ? 'none' : implode(', ', $searchedWorkspaces);
        $message = "Package [{$packageName}] was not found in any registered workspace [{$workspacesStr}].";
        $solution = "Check the package name spelling or create it first using 'php artisan package:make {$packageName}'.";

        parent::__construct($message, $solution);
    }
}
