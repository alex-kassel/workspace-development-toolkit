<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Unit;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\PackageGraph;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;

class PackageGraphTest extends TestCase
{
    protected PackageGraph $graph;

    protected function setUp(): void
    {
        parent::setUp();
        $this->graph = app(PackageGraph::class);
    }

    public function test_graph_detects_dependencies_and_dependents(): void
    {
        Workspace::add('packages');

        $coreDir = $this->tempDir.'/packages/acme/core';
        $serviceDir = $this->tempDir.'/packages/acme/service';
        $billingDir = $this->tempDir.'/packages/acme/billing';

        File::ensureDirectoryExists($coreDir);
        File::ensureDirectoryExists($serviceDir);
        File::ensureDirectoryExists($billingDir);

        File::put($coreDir.'/composer.json', json_encode(['name' => 'acme/core']));
        File::put($serviceDir.'/composer.json', json_encode([
            'name' => 'acme/service',
            'require' => ['acme/core' => '^1.0'],
        ]));
        File::put($billingDir.'/composer.json', json_encode([
            'name' => 'acme/billing',
            'require' => ['acme/service' => '^1.0'],
        ]));

        $this->graph->clearCache();

        $this->assertSame(['acme/core'], $this->graph->getDependencies('acme/service'));
        $this->assertSame(['acme/service'], $this->graph->getDependencies('acme/billing'));
        $this->assertSame([], $this->graph->getDependencies('acme/core'));

        $this->assertSame(['acme/service'], $this->graph->getDependents('acme/core', recursive: false));
        $this->assertSame(['acme/service', 'acme/billing'], $this->graph->getDependents('acme/core', recursive: true));

        $this->assertEmpty($this->graph->detectCycles());

        $tree = $this->graph->renderTree('acme/core');
        $this->assertStringContainsString('acme/core', $tree);
        $this->assertStringContainsString('acme/service', $tree);

        $mermaid = $this->graph->renderMermaid();
        $this->assertStringContainsString('graph TD', $mermaid);
    }

    public function test_graph_detects_cycles(): void
    {
        Workspace::add('packages');

        $pkgADir = $this->tempDir.'/packages/acme/pkg-a';
        $pkgBDir = $this->tempDir.'/packages/acme/pkg-b';

        File::ensureDirectoryExists($pkgADir);
        File::ensureDirectoryExists($pkgBDir);

        File::put($pkgADir.'/composer.json', json_encode([
            'name' => 'acme/pkg-a',
            'require' => ['acme/pkg-b' => '^1.0'],
        ]));
        File::put($pkgBDir.'/composer.json', json_encode([
            'name' => 'acme/pkg-b',
            'require' => ['acme/pkg-a' => '^1.0'],
        ]));

        $this->graph->clearCache();

        $cycles = $this->graph->detectCycles();
        $this->assertNotEmpty($cycles);
    }
}
