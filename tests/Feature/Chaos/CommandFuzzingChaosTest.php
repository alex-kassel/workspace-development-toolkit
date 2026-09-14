<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature\Chaos;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\Support\Chaos\CommandDiscovery;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\Support\Chaos\FuzzPayloadGenerator;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;

class CommandFuzzingChaosTest extends TestCase
{
    public function test_all_discovered_commands_survive_path_traversal_fuzzing(): void
    {
        $commands = CommandDiscovery::allCommands();

        $this->assertGreaterThanOrEqual(15, count($commands), 'Self-growing test failed: could not discover toolkit commands.');

        // Initialize a standard sandbox workspace
        Workspace::add('packages', null, true);

        $traversalVectors = FuzzPayloadGenerator::pathTraversalVectors();

        foreach ($commands as $commandName => $commandInstance) {
            $definition = $commandInstance->getDefinition();
            $arguments = $definition->getArguments();

            // Find required or string arguments
            foreach ($arguments as $argName => $arg) {
                foreach ($traversalVectors as $vector) {
                    $parameters = [$argName => $vector];

                    // If command has other required arguments, provide placeholders
                    foreach ($arguments as $otherArgName => $otherArg) {
                        if ($otherArgName !== $argName && $otherArg->isRequired()) {
                            $parameters[$otherArgName] = 'dummy-val';
                        }
                    }

                    $parameters['--no-interaction'] = true;

                    // Execution contract: must NEVER terminate with unhandled exception/crash
                    $status = $this->artisan($commandName, $parameters)->run();

                    $this->assertContains(
                        $status,
                        [0, 1, 2],
                        "Command [{$commandName}] with traversal input [{$vector}] exited with illegal code [{$status}]."
                    );
                }
            }
        }
    }

    public function test_all_discovered_commands_survive_malformed_and_extreme_inputs(): void
    {
        $commands = CommandDiscovery::allCommands();
        Workspace::add('packages', null, true);

        $malformedVectors = FuzzPayloadGenerator::malformedInputs();

        foreach ($commands as $commandName => $commandInstance) {
            $definition = $commandInstance->getDefinition();
            $arguments = $definition->getArguments();

            foreach ($arguments as $argName => $arg) {
                foreach ($malformedVectors as $vector) {
                    $parameters = [$argName => $vector];

                    foreach ($arguments as $otherArgName => $otherArg) {
                        if ($otherArgName !== $argName && $otherArg->isRequired()) {
                            $parameters[$otherArgName] = 'dummy-val';
                        }
                    }

                    $parameters['--no-interaction'] = true;

                    $status = $this->artisan($commandName, $parameters)->run();

                    $this->assertContains(
                        $status,
                        [0, 1, 2],
                        "Command [{$commandName}] crashed on malformed vector [{$vector}]."
                    );
                }
            }
        }
    }

    public function test_option_traversal_fuzzing_does_not_escape_sandbox(): void
    {
        Workspace::add('packages', null, true);

        $targetCommands = [
            'package:make' => ['package' => 'acme/clean-pkg', '--workspace' => '../../../../etc'],
            'package:clone' => ['package' => 'acme/clean-pkg', '--workspace' => '../../../../etc'],
            'workspace:ci-matrix' => ['--file' => '../../../../tmp/ci-matrix.json'],
        ];

        foreach ($targetCommands as $command => $params) {
            $status = $this->artisan($command, $params)->run();

            $this->assertContains(
                $status,
                [0, 1, 2],
                "Command [{$command}] with escaped option crashed with status [{$status}]."
            );

            $this->assertFileDoesNotExist(base_path('../../../../etc/composer.json'));
        }
    }
}
