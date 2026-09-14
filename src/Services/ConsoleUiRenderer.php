<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\Events\ConsoleDiagnosticDispatched;
use Symfony\Component\Console\Output\OutputInterface;

class ConsoleUiRenderer
{
    /**
     * Render a structured diagnostic card to the console output.
     */
    public function render(ConsoleDiagnosticDispatched $event, ?OutputInterface $output = null): void
    {
        $out = $output ?? $event->output;
        if ($out === null) {
            return;
        }

        $badge = $event->severity->badge();
        $colorTag = $event->severity->colorTag();
        $title = "[{$event->code}] {$event->message}";

        $out->writeln('');
        $this->renderCard($out, $badge, $colorTag, $title);

        if (! empty($event->context)) {
            $out->writeln('');
            foreach ($event->context as $key => $value) {
                if (is_array($value)) {
                    $out->writeln("  <fg=gray>{$key}:</>");
                    foreach ($value as $item) {
                        $out->writeln("  <fg=cyan>•</> {$item}");
                    }
                } else {
                    $label = is_string($key) ? "{$key}: " : '';
                    $out->writeln("  <fg=gray>{$label}</>{$value}");
                }
            }
        }

        if (! empty($event->remediationSteps)) {
            $out->writeln('');
            $out->writeln('  <comment>How to fix:</comment>');
            foreach ($event->remediationSteps as $step) {
                $out->writeln("  <fg=green>•</> {$step}");
            }
        }

        if ($event->agentGuidance !== null && trim($event->agentGuidance) !== '') {
            $out->writeln('');
            $out->writeln('  <fg=gray>Agent guidance:</>');
            $out->writeln("  <fg=gray>• {$event->agentGuidance}</>");
        }

        $out->writeln('');
    }

    /**
     * Render an elegant framed card box.
     */
    protected function renderCard(OutputInterface $out, string $badge, string $colorTag, string $title): void
    {
        $minWidth = 64;
        $lines = explode("\n", $title);
        $maxLineLen = 0;
        foreach ($lines as $line) {
            $clean = strip_tags($line);
            $maxLineLen = max($maxLineLen, mb_strlen($clean));
        }

        $cardWidth = max($minWidth, $maxLineLen + 6);

        // Top border with badge
        $badgeText = " {$badge} ";
        $badgeLen = mb_strlen($badgeText);
        $remainingTop = max(2, $cardWidth - $badgeLen - 3);
        $topBorder = " <{$colorTag}>┌─{$badgeText}".str_repeat('─', $remainingTop).'┐</>';
        $out->writeln($topBorder);

        // Content lines
        foreach ($lines as $line) {
            $cleanLine = strip_tags($line);
            $padding = max(0, $cardWidth - 4 - mb_strlen($cleanLine));
            $out->writeln(" <{$colorTag}>│</> <options=bold>{$line}</>".str_repeat(' ', $padding)." <{$colorTag}>│</>");
        }

        // Bottom border
        $bottomBorder = " <{$colorTag}>└".str_repeat('─', $cardWidth - 2).'┘</>';
        $out->writeln($bottomBorder);
    }
}
