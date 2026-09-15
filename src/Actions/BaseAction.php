<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Actions;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\ActionStep;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\ActionExecutionException;
use Generator;

/**
 * Base Action for streaming generator-based steps.
 *
 * @method Generator<int, ActionStep> execute(mixed ...$args)
 */
abstract class BaseAction
{
    /**
     * Run the action and eagerly consume all yielded steps.
     *
     * @param  mixed  ...$args
     *
     * @throws ActionExecutionException
     */
    public function run(...$args): void
    {
        if (! method_exists($this, 'execute')) {
            throw new ActionExecutionException(static::class);
        }

        /** @var Generator<int, ActionStep> $generator */
        $generator = $this->execute(...$args);

        foreach ($generator as $_) {
            // Eagerly consume all yielded steps
        }
    }
}
