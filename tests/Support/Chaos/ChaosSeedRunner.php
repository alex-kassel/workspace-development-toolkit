<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Support\Chaos;

final class ChaosSeedRunner
{
    private int $seed;

    /**
     * @var list<string>
     */
    private array $trace = [];

    public function __construct(?int $seed = null)
    {
        if ($seed !== null) {
            $this->seed = $seed;
        } elseif (getenv('CHAOS_SEED') !== false && is_numeric(getenv('CHAOS_SEED'))) {
            $this->seed = (int) getenv('CHAOS_SEED');
        } else {
            $this->seed = crc32(uniqid('chaos_', true));
        }

        mt_srand($this->seed);
    }

    public function getSeed(): int
    {
        return $this->seed;
    }

    /**
     * Pick a random integer between min and max inclusive.
     */
    public function randomInt(int $min, int $max): int
    {
        return mt_rand($min, $max);
    }

    /**
     * Pick a random item from a non-empty array.
     *
     * @template T
     *
     * @param  list<T>  $items
     * @return T
     */
    public function randomChoice(array $items): mixed
    {
        if (empty($items)) {
            throw new \InvalidArgumentException('Cannot pick random item from empty array.');
        }

        $index = mt_rand(0, count($items) - 1);

        return $items[$index];
    }

    /**
     * Record and run a step.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function step(string $description, callable $callback): mixed
    {
        $stepIndex = count($this->trace) + 1;
        $breadcrumb = "[Step #{$stepIndex}] {$description}";
        $this->trace[] = $breadcrumb;

        try {
            return $callback();
        } catch (\Throwable $e) {
            $report = "\n================ CHAOS RUNNER FAILURE ================\n";
            $report .= "Seed: {$this->seed}\n";
            $report .= "Failed at: {$breadcrumb}\n";
            $report .= "Trace history:\n  • ".implode("\n  • ", $this->trace)."\n";
            $report .= "To reproduce this exact run:\n";
            $report .= "CHAOS_SEED={$this->seed} ./vendor/bin/phpunit -c packages/alex-kassel/workspace-development-toolkit/phpunit.xml.dist packages/alex-kassel/workspace-development-toolkit/tests/Feature/Chaos/StateChaosMachineTest.php\n";
            $report .= "======================================================\n";

            throw new \RuntimeException($report."\nUnderlying Exception: ".$e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /**
     * Get recorded trace breadcrumbs.
     *
     * @return list<string>
     */
    public function getTrace(): array
    {
        return $this->trace;
    }
}
