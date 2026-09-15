<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\DTOs;

class ActionStep
{
    public function __construct(
        public readonly string $status,
        public readonly string $message,
    ) {}

    public static function created(string $message): self
    {
        return new self('created', $message);
    }

    public static function updated(string $message): self
    {
        return new self('updated', $message);
    }

    public static function linked(string $message): self
    {
        return new self('linked', $message);
    }

    public static function cleaned(string $message): self
    {
        return new self('cleaned', $message);
    }

    public static function skipped(string $message): self
    {
        return new self('skipped', $message);
    }

    public static function executed(string $message): self
    {
        return new self('executed', $message);
    }

    public static function failed(string $message): self
    {
        return new self('failed', $message);
    }
}
