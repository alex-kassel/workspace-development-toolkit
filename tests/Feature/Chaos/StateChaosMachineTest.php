<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature\Chaos;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\Support\Chaos\ChaosSeedRunner;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\Support\Chaos\SabotageEngine;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;

class StateChaosMachineTest extends TestCase
{
    public function test_deterministic_state_machine_survives_rapid_chaos_and_sabotage(): void
    {
        $runner = new ChaosSeedRunner;

        // 1. Initial workspace setup
        $runner->step('Initialize workspace', function () {
            Workspace::add('packages', null, true);
        });

        $createdPackages = [];
        $vendors = ['alpha', 'beta', 'gamma'];
        $pkgSuffixes = ['core', 'utils', 'api', 'client', 'engine'];

        // Perform 25 randomized chaotic transitions
        for ($i = 1; $i <= 25; $i++) {
            $actionType = $runner->randomInt(1, 10);

            switch ($actionType) {
                case 1: // Create a new package
                    $vendor = $runner->randomChoice($vendors);
                    $suffix = $runner->randomChoice($pkgSuffixes);
                    $name = "{$vendor}/{$suffix}-{$i}";

                    $runner->step("Scaffold package [{$name}]", function () use ($name, &$createdPackages) {
                        $exit = $this->artisan('package:make', ['package' => $name])->run();
                        $this->assertContains($exit, [0, 1]);
                        if ($exit === 0) {
                            $createdPackages[] = $name;
                        }
                    });
                    break;

                case 2: // Delete an existing or ghost package
                    $target = ! empty($createdPackages) && $runner->randomInt(1, 2) === 1
                        ? $runner->randomChoice($createdPackages)
                        : 'ghost/non-existent-'.$runner->randomInt(1, 999);

                    $runner->step("Delete package [{$target}]", function () use ($target, &$createdPackages) {
                        $exit = $this->artisan('package:delete', ['package' => $target, '--force' => true])->run();
                        $this->assertContains($exit, [0, 1]);
                        $createdPackages = array_values(array_filter($createdPackages, fn ($p) => $p !== $target));
                    });
                    break;

                case 3: // Sabotage: corrupt workspace.json and verify self-healing
                    $runner->step('Sabotage workspace.json and trigger self-heal via workspace:list', function () {
                        SabotageEngine::corruptWorkspaceJson(base_path());
                        $exit = $this->artisan('workspace:list')->run();
                        $this->assertSame(0, $exit);

                        $content = File::get(base_path('workspace.json'));
                        $this->assertIsArray(json_decode($content, true));
                    });
                    break;

                case 4: // Sabotage: delete package directory behind back and run sync
                    if (! empty($createdPackages)) {
                        $target = $runner->randomChoice($createdPackages);
                        $runner->step("Delete package [{$target}] behind back and sync", function () use ($target) {
                            $path = base_path('packages/'.$target);
                            SabotageEngine::deletePackageBehindBack($path);

                            $exit = $this->artisan('workspace:sync')->run();
                            $this->assertSame(0, $exit);
                        });
                    }
                    break;

                case 5: // Sabotage: corrupt package composer.json and run workspace:status
                    if (! empty($createdPackages)) {
                        $target = $runner->randomChoice($createdPackages);
                        $runner->step("Corrupt composer.json of package [{$target}] and check status", function () use ($target) {
                            $path = base_path('packages/'.$target);
                            if (File::isDirectory($path)) {
                                SabotageEngine::corruptPackageComposerJson($path);
                            }

                            $exit = $this->artisan('workspace:status')->run();
                            $this->assertContains($exit, [0, 1]);
                        });
                    }
                    break;

                case 6: // Inject alien unmanaged directory into workspace
                    $runner->step('Inject alien directory and verify workspace:sync ignores it cleanly', function () use ($runner) {
                        $alienDir = 'alien-dir-'.$runner->randomInt(1, 999);
                        SabotageEngine::injectAlienDirectory(base_path('packages'), $alienDir);

                        $exit = $this->artisan('workspace:sync')->run();
                        $this->assertSame(0, $exit);
                    });
                    break;

                case 7: // Run package:check on an existing or ghost package
                    $target = ! empty($createdPackages)
                        ? $runner->randomChoice($createdPackages)
                        : 'ghost/pkg';

                    $runner->step("Run package:check on [{$target}]", function () use ($target) {
                        $exit = $this->artisan('package:check', ['package' => $target])->run();
                        $this->assertContains($exit, [0, 1]);
                    });
                    break;

                case 8: // Run package:deps
                    $target = ! empty($createdPackages)
                        ? $runner->randomChoice($createdPackages)
                        : 'ghost/pkg';

                    $runner->step("Run package:deps on [{$target}]", function () use ($target) {
                        $exit = $this->artisan('package:deps', ['package' => $target])->run();
                        $this->assertContains($exit, [0, 1]);
                    });
                    break;

                case 9: // Run package:readme
                    $target = ! empty($createdPackages)
                        ? $runner->randomChoice($createdPackages)
                        : 'ghost/pkg';

                    $runner->step("Run package:readme on [{$target}]", function () use ($target) {
                        $exit = $this->artisan('package:readme', ['package' => $target])->run();
                        $this->assertContains($exit, [0, 1]);
                    });
                    break;

                case 10: // Run workspace:sync
                    $runner->step('Run workspace:sync', function () {
                        $exit = $this->artisan('workspace:sync')->run();
                        $this->assertSame(0, $exit);
                    });
                    break;
            }

            // Invariant check: workspace.json must always remain valid JSON
            if (File::exists(base_path('workspace.json'))) {
                $content = File::get(base_path('workspace.json'));
                $this->assertNotNull(
                    json_decode($content, true),
                    "Corruption invariant failed at step {$i} (Seed: {$runner->getSeed()})"
                );
            }
        }
    }
}
