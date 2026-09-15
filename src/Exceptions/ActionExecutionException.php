<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Exceptions;

class ActionExecutionException extends WorkspaceException
{
    public function __construct(string $actionClass, ?string $message = null)
    {
        $desc = $message ?? "Action class [{$actionClass}] must implement an execute() generator method.";
        $solution = "Define a public function execute(...): \\Generator in [{$actionClass}] yielding \\AlexKassel\\WorkspaceDevelopmentToolkit\\DTOs\\ActionStep instances.";

        parent::__construct($desc, $solution);
    }

    public function errorCode(): string
    {
        return 'ACTION_EXECUTE_METHOD_MISSING';
    }
}
