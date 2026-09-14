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
        $code = $this->errorCode();
        $base = "[{$code}] {$this->getMessage()}";

        return $this->solution
            ? "{$base}\nHow to fix: {$this->solution}"
            : $base;
    }

    public function getSolution(): ?string
    {
        return $this->solution;
    }

    /**
     * Machine-readable error code for logging and agent triage.
     */
    public function errorCode(): string
    {
        return 'WS_GENERAL_ERROR';
    }

    /**
     * Discrete list of actionable remediation steps.
     *
     * @return array<int, string>
     */
    public function remediationSteps(): array
    {
        if ($this->solution === null || trim($this->solution) === '') {
            return [];
        }

        $lines = array_filter(array_map('trim', explode("\n", $this->solution)));
        $steps = [];
        foreach ($lines as $line) {
            $cleaned = ltrim($line, "•- \t");
            if ($cleaned !== '') {
                $steps[] = $cleaned;
            }
        }

        return $steps ?: [$this->solution];
    }

    /**
     * Actionable prompt and protocol instructions for AI coding agents.
     */
    public function agentInstructions(): ?string
    {
        return null;
    }
}
