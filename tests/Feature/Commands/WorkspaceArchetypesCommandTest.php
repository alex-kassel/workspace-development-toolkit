<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;

class WorkspaceArchetypesCommandTest extends TestCase
{
    public function test_lists_archetypes_in_table(): void
    {
        $this->artisan('workspace:archetypes')
            ->assertSuccessful()
            ->expectsOutputToContain('Available Package Scaffolding Archetypes')
            ->expectsOutputToContain('library')
            ->expectsOutputToContain('pest')
            ->expectsOutputToContain('ddd-module')
            ->expectsOutputToContain('minimal');
    }

    public function test_lists_archetypes_in_json(): void
    {
        $this->artisan('workspace:archetypes', ['--json' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('"name": "library"')
            ->expectsOutputToContain('"name": "pest"');
    }
}
