<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Unit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\Services\PackageCloner;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;

class PackageClonerTest extends TestCase
{
    protected PackageCloner $cloner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cloner = app(PackageCloner::class);
    }

    public function test_link_host_agents_guideline_returns_false_when_stub_missing(): void
    {
        $packageRelPath = 'packages/alex-kassel/my-package';
        File::ensureDirectoryExists(base_path($packageRelPath));

        $result = $this->cloner->linkHostAgentsGuideline($packageRelPath);

        $this->assertFalse($result);
        $this->assertFileDoesNotExist(base_path('AGENTS.md'));
    }

    public function test_link_host_agents_guideline_creates_symlink(): void
    {
        $packageRelPath = 'packages/alex-kassel/workspace-development-toolkit';
        $stubDir = base_path($packageRelPath.'/stubs');
        File::ensureDirectoryExists($stubDir);
        File::put($stubDir.'/AGENTS.md.stub', '# Custom Toolkit Guidelines');

        $result = $this->cloner->linkHostAgentsGuideline($packageRelPath);

        $this->assertTrue($result);
        $this->assertTrue(is_link(base_path('AGENTS.md')));
        $this->assertSame(
            'packages/alex-kassel/workspace-development-toolkit/stubs/AGENTS.md.stub',
            readlink(base_path('AGENTS.md'))
        );
        $this->assertSame('# Custom Toolkit Guidelines', file_get_contents(base_path('AGENTS.md')));
    }

    public function test_link_host_agents_guideline_preserves_host_content_into_stub_before_linking(): void
    {
        $packageRelPath = 'packages/alex-kassel/workspace-development-toolkit';
        $stubDir = base_path($packageRelPath.'/stubs');
        File::ensureDirectoryExists($stubDir);
        File::put($stubDir.'/AGENTS.md.stub', '# Old Stub Guidelines');

        // Create an existing real file with fresh rules in host root
        File::put(base_path('AGENTS.md'), '# Fresh Host Project Guidelines');

        $result = $this->cloner->linkHostAgentsGuideline($packageRelPath);

        $this->assertTrue($result);
        $this->assertTrue(is_link(base_path('AGENTS.md')));
        // Assert the stub was updated with the host project's rules before linking
        $this->assertSame('# Fresh Host Project Guidelines', file_get_contents($stubDir.'/AGENTS.md.stub'));
    }

    public function test_link_host_agents_guideline_is_idempotent(): void
    {
        $packageRelPath = 'packages/alex-kassel/workspace-development-toolkit';
        $stubDir = base_path($packageRelPath.'/stubs');
        File::ensureDirectoryExists($stubDir);
        File::put($stubDir.'/AGENTS.md.stub', '# Idempotent Guidelines');

        $firstRun = $this->cloner->linkHostAgentsGuideline($packageRelPath);
        $secondRun = $this->cloner->linkHostAgentsGuideline($packageRelPath);

        $this->assertTrue($firstRun);
        $this->assertTrue($secondRun);
        $this->assertTrue(is_link(base_path('AGENTS.md')));
    }
}
