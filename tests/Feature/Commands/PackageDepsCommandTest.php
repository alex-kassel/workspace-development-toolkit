<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;

class PackageDepsCommandTest extends TestCase
{
    public function test_package_deps_displays_tree_and_mermaid(): void
    {
        Workspace::add('packages');

        $coreDir = $this->tempDir.'/packages/acme/core';
        $serviceDir = $this->tempDir.'/packages/acme/service';

        File::ensureDirectoryExists($coreDir);
        File::ensureDirectoryExists($serviceDir);

        File::put($coreDir.'/composer.json', json_encode(['name' => 'acme/core']));
        File::put($serviceDir.'/composer.json', json_encode([
            'name' => 'acme/service',
            'require' => ['acme/core' => '^1.0'],
        ]));

        Workspace::sync();

        $this->artisan('package:deps', ['package' => 'acme/core'])
            ->expectsOutputToContain('Dependency Graph for [acme/core]')
            ->expectsOutputToContain('Dependents (Used By):')
            ->expectsOutputToContain('acme/service')
            ->assertSuccessful();

        $this->artisan('package:deps', ['package' => 'acme/core', '--mermaid' => true])
            ->expectsOutputToContain('graph TD')
            ->assertSuccessful();

        $this->artisan('package:deps', ['package' => 'acme/core', '--json' => true])
            ->assertSuccessful();
    }
}
