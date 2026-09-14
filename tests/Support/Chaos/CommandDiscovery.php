<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Support\Chaos;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use ReflectionClass;

final class CommandDiscovery
{
    /**
     * Locate and instantiate all concrete Command classes in the toolkit.
     *
     * @return array<string, Command> Keyed by Artisan command name (e.g. 'package:make')
     */
    public static function allCommands(): array
    {
        $commandsPath = dirname(__DIR__, 3).'/src/Commands';
        $files = File::glob($commandsPath.'/*Command.php') ?: [];

        $discovered = [];

        foreach ($files as $file) {
            $className = 'AlexKassel\\WorkspaceDevelopmentToolkit\\Commands\\'.basename($file, '.php');

            if (! class_exists($className)) {
                continue;
            }

            $ref = new ReflectionClass($className);
            if ($ref->isAbstract() || ! $ref->isSubclassOf(Command::class)) {
                continue;
            }

            try {
                /** @var Command $instance */
                $instance = app($className);
                $name = $instance->getName();
                if ($name !== null && $name !== '') {
                    $discovered[$name] = $instance;
                }
            } catch (\Throwable) {
                // If container resolution fails in test context, skip or instantiate via reflection
            }
        }

        return $discovered;
    }
}
