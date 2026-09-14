<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Unit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\Services\StubResolver;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;

class StubResolverTest extends TestCase
{
    protected StubResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(StubResolver::class);
    }

    public function test_resolves_default_bundled_stubs(): void
    {
        $result = $this->resolver->resolve('packages');

        $this->assertNotEmpty($result->fileMap);
        $this->assertArrayHasKey('composer.json', $result->fileMap);
        $this->assertArrayHasKey('src/{{ providerClass }}.php', $result->fileMap);
        $this->assertArrayHasKey('LICENSE', $result->fileMap);
        $this->assertArrayHasKey('phpunit.xml', $result->fileMap);
        $this->assertContains('bundled:default', $result->appliedTiers);
    }

    public function test_resolves_minimal_archetype_and_excludes_files(): void
    {
        $result = $this->resolver->resolve('packages', 'minimal');

        $this->assertArrayHasKey('composer.json', $result->fileMap);
        $this->assertArrayHasKey('src/{{ providerClass }}.php', $result->fileMap);
        $this->assertArrayNotHasKey('LICENSE', $result->fileMap);
        $this->assertArrayNotHasKey('phpunit.xml', $result->fileMap);
        $this->assertArrayNotHasKey('.github/workflows/run-tests.yml', $result->fileMap);
        $this->assertContains('LICENSE', $result->excludedFiles);
    }

    public function test_resolves_pest_archetype_and_replaces_test_stubs(): void
    {
        $result = $this->resolver->resolve('packages', 'pest');

        $this->assertArrayHasKey('composer.json', $result->fileMap);
        $this->assertArrayHasKey('tests/Pest.php', $result->fileMap);
        $this->assertArrayHasKey('tests/Feature/ExampleTest.php', $result->fileMap);
        $this->assertArrayNotHasKey('tests/Unit/ExampleTest.php', $result->fileMap);
    }

    public function test_resolves_ddd_module_archetype(): void
    {
        $result = $this->resolver->resolve('packages', 'ddd-module');

        $this->assertArrayHasKey('src/Domain/.gitkeep', $result->fileMap);
        $this->assertArrayHasKey('src/Actions/.gitkeep', $result->fileMap);
        $this->assertArrayHasKey('src/DTOs/.gitkeep', $result->fileMap);
        $this->assertArrayHasKey('src/Contracts/.gitkeep', $result->fileMap);
    }

    public function test_workspace_specific_stubs_override_and_ignore(): void
    {
        $wsDir = base_path('test_ws');
        $stubsDir = "{$wsDir}/.stubs";
        File::ensureDirectoryExists($stubsDir);

        // Put custom README and an ignore marker for LICENSE
        File::put("{$stubsDir}/README.md.stub", '# Custom Workspace README');
        File::put("{$stubsDir}/LICENSE.ignore", '');

        try {
            $result = $this->resolver->resolve('test_ws');

            $this->assertArrayHasKey('README.md', $result->fileMap);
            $this->assertSame("{$stubsDir}/README.md.stub", $result->fileMap['README.md']);
            $this->assertArrayNotHasKey('LICENSE', $result->fileMap);
            $this->assertContains('LICENSE', $result->excludedFiles);
        } finally {
            File::deleteDirectory($wsDir);
        }
    }

    public function test_get_available_archetypes_lists_bundled_archetypes(): void
    {
        $archetypes = $this->resolver->getAvailableArchetypes();

        $this->assertArrayHasKey('library', $archetypes);
        $this->assertArrayHasKey('pest', $archetypes);
        $this->assertArrayHasKey('ddd-module', $archetypes);
        $this->assertArrayHasKey('minimal', $archetypes);
    }
}
