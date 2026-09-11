<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Exceptions;

use RuntimeException;
use Throwable;

class WorkspaceException extends RuntimeException
{
    public function __construct(
        string $message,
        protected ?string $solution = null,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getFormattedMessage(): string
    {
        return $this->solution
            ? "{$this->getMessage()}\nHow to fix: {$this->solution}"
            : $this->getMessage();
    }

    public function getSolution(): ?string
    {
        return $this->solution;
    }
}
