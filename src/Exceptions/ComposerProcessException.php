<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Exceptions;

class ComposerProcessException extends WorkspaceException
{
    public function __construct(
        public readonly string $command,
        public readonly string $output,
    ) {
        $message = "Composer command [{$command}] failed: {$output}";
        $solution = 'Ensure Composer is installed and operational, check your network connection if downloading packages, and inspect the Composer output above.';

        parent::__construct($message, $solution);
    }
}
