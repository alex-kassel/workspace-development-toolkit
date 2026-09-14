<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Exceptions;

class InvalidWorkspacePathException extends WorkspaceException
{
    public function __construct(string $path, string $reason)
    {
        parent::__construct(
            "Invalid workspace path [{$path}]: {$reason}.",
            'Specify a clean relative directory path inside the application (e.g. "packages" or "clients/acme"). Paths may not be absolute, reference parent directories (".."), or escape the project root.'
        );
    }

    public function errorCode(): string
    {
        return 'WS_INVALID_WORKSPACE_PATH';
    }

    public function agentInstructions(): ?string
    {
        return 'Provide a clean relative path within project root without parent references ("..") or leading slashes.';
    }
}
