<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Enums;

enum CheckStatus: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case ActionRequired = 'action_required';

    public function badge(bool $bracketed = false): string
    {
        $label = match ($this) {
            self::Passed => '<fg=green>PASS</>',
            self::Failed => '<fg=red>FAIL</>',
            self::Skipped => '<fg=yellow>SKIP</>',
            self::ActionRequired => '<comment>WARN</comment>',
        };

        return $bracketed ? "[{$label}]" : $label;
    }

    public static function format(string $status, bool $bracketed = false): string
    {
        $normalized = strtolower(trim($status));
        $enum = match ($normalized) {
            'passed', 'pass', 'ok' => self::Passed,
            'failed', 'fail' => self::Failed,
            'skipped', 'skip', 'not_configured' => self::Skipped,
            'action_required', 'warn', 'warning' => self::ActionRequired,
            default => null,
        };

        if ($enum !== null) {
            return $enum->badge($bracketed);
        }

        $upper = strtoupper($status);

        return $bracketed ? "[{$upper}]" : $upper;
    }
}
