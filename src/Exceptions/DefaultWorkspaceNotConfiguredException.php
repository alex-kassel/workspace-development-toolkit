<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Exceptions;

class DefaultWorkspaceNotConfiguredException extends WorkspaceException
{
    public function __construct()
    {
        $message = 'No default workspace is currently configured.';
        $solution = "Register a workspace using 'php artisan workspace:register packages' or set an existing one as default using 'php artisan workspace:default packages'.";

        parent::__construct($message, $solution);
    }

    public function errorCode(): string
    {
        return 'WS_DEFAULT_WORKSPACE_MISSING';
    }

    public function agentInstructions(): ?string
    {
        return "Configure a default workspace using 'php artisan workspace:default <workspace>' or register one with 'php artisan workspace:register <workspace> --default'.";
    }
}
