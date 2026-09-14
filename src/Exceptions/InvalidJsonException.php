<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Exceptions;

use Throwable;

class InvalidJsonException extends WorkspaceException
{
    public function __construct(
        public readonly string $path,
        string $error,
        ?Throwable $previous = null,
    ) {
        $message = "Failed to parse JSON file [{$path}]: {$error}";
        $solution = "Inspect [{$path}] for syntax errors (e.g. missing or trailing comma, unmatched quotes) or delete the file to allow re-generation.";

        parent::__construct($message, $solution, 0, $previous);
    }

    public function errorCode(): string
    {
        return 'WS_INVALID_JSON';
    }

    public function agentInstructions(): ?string
    {
        return "Validate and fix the JSON syntax in file [{$this->path}].";
    }
}
