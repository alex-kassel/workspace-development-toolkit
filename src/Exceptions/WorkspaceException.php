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
        $formatted = $solution
            ? "{$message}\nHow to fix: {$solution}"
            : $message;

        parent::__construct($formatted, $code, $previous);
    }

    public function getSolution(): ?string
    {
        return $this->solution;
    }
}
