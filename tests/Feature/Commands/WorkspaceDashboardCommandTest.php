<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceDashboardCollector;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;

class WorkspaceDashboardCommandTest extends TestCase
{
    public function test_dashboard_json_output(): void
    {
        $this->artisan('workspace:dashboard', ['--json' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('"summary":')
            ->expectsOutputToContain('"packages":');
    }

    public function test_dashboard_non_interactive_table(): void
    {
        $this->artisan('workspace:dashboard', ['--no-interaction' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('WORKSPACE MISSION CONTROL');
    }

    public function test_dashboard_collector_filters_workspace(): void
    {
        /** @var WorkspaceDashboardCollector $collector */
        $collector = app(WorkspaceDashboardCollector::class);
        $dto = $collector->collect('non-existent-ws');

        $this->assertSame(0, $dto->totalPackages);
    }
}
