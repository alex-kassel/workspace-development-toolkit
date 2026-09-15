<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Actions;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\ActionStep;
use Generator;

abstract class BaseAction
{
    /**
     * Run the action and eagerly consume all yielded steps.
     *
     * @param  mixed  ...$args
     */
    public function run(...$args): void
    {
        /** @var Generator<int, ActionStep> $generator */
        $generator = $this->execute(...$args);

        foreach ($generator as $_) {
            // Eagerly consume all yielded steps
        }
    }
}
