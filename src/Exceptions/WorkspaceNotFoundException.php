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
        $message = "Workspace [{$workspace}] is not registered.";
        $solution = "Register the workspace using 'php artisan workspace:register {$workspace}' or choose from the available workspaces in 'php artisan workspace:list'.";

        parent::__construct($message, $solution);
    }

    public function errorCode(): string
    {
        return 'WS_WORKSPACE_NOT_FOUND';
    }

    public function agentInstructions(): ?string
    {
        return "Register the workspace using 'php artisan workspace:register {$this->workspace}' or inspect valid workspaces with 'php artisan workspace:list'.";
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function diagnosticContext(): array
    {
        return [
            'Available workspaces' => empty($this->available) ? ['(none registered)'] : $this->available,
        ];
    }
}
