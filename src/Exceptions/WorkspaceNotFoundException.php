<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Exceptions;

class WorkspaceNotFoundException extends WorkspaceException
{
    /**
     * @param  array<int, string>  $available
     */
    public function __construct(
        public readonly string $workspace,
        public readonly array $available = [],
    ) {
        $availableStr = empty($available) ? 'none' : implode(', ', $available);
        $message = "Workspace [{$workspace}] is not registered. Available workspaces: [{$availableStr}].";
        $solution = "Register the workspace using 'php artisan workspace:add {$workspace}' or choose from the available workspaces in 'php artisan workspace:list'.";

        parent::__construct($message, $solution);
    }

    public function errorCode(): string
    {
        return 'WS_WORKSPACE_NOT_FOUND';
    }

    public function agentInstructions(): ?string
    {
        return "Register the workspace using 'php artisan workspace:add {$this->workspace}' or inspect valid workspaces with 'php artisan workspace:list'.";
    }
}
