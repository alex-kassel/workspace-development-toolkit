<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Exceptions;

class DefaultWorkspaceNotConfiguredException extends WorkspaceException
{
    public function __construct()
    {
        $message = 'No default workspace is currently configured.';
        $solution = "Add a workspace using 'php artisan workspace:add packages' or set an existing one as default using 'php artisan workspace:default packages'.";

        parent::__construct($message, $solution);
    }
}
