<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Exceptions;

class ComposerProcessException extends WorkspaceException
{
    public function __construct(
        public readonly string $command,
        public readonly int $exitCode,
        public readonly string $output,
    ) {
        $cleanOutput = trim($output);
        $message = "Composer command [{$command}] failed with exit code [{$exitCode}]".($cleanOutput !== '' ? ": {$cleanOutput}" : '.');
        $solution = 'Ensure Composer is installed and operational, check your network connection if downloading packages, and inspect the Composer output above.';

        parent::__construct($message, $solution);
    }

    public function errorCode(): string
    {
        return 'WS_COMPOSER_FAILED';
    }

    public function agentInstructions(): ?string
    {
        return 'Inspect the raw Composer output above, resolve package constraint conflicts, or check network/connectivity if pulling packages.';
    }
}
