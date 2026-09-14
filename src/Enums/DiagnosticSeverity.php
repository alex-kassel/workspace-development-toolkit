<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Enums;

enum DiagnosticSeverity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';

    public function badge(): string
    {
        return match ($this) {
            self::Error => 'ERROR',
            self::Warning => 'WARNING',
            self::Info => 'INFO',
        };
    }

    public function colorTag(): string
    {
        return match ($this) {
            self::Error => 'fg=red;options=bold',
            self::Warning => 'fg=yellow;options=bold',
            self::Info => 'fg=cyan;options=bold',
        };
    }
}
