<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Unit;

use AlexKassel\WorkspaceDevelopmentToolkit\Services\CiMatrixGenerator;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

class CiMatrixGeneratorTest extends TestCase
{
    protected CiMatrixGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = app(CiMatrixGenerator::class);
    }

    public function test_resolves_php_versions_correctly(): void
    {
        // Null or empty constraint returns all supported versions
        $this->assertSame(['8.2', '8.3', '8.4'], $this->generator->resolvePhpVersions(null));
        $this->assertSame(['8.2', '8.3', '8.4'], $this->generator->resolvePhpVersions(''));

        // ^8.2 supports 8.2, 8.3, 8.4
        $this->assertSame(['8.2', '8.3', '8.4'], $this->generator->resolvePhpVersions('^8.2'));

        // ^8.3 supports 8.3 and 8.4 only
        $this->assertSame(['8.3', '8.4'], $this->generator->resolvePhpVersions('^8.3'));

        // >=8.3 supports 8.3 and 8.4
        $this->assertSame(['8.3', '8.4'], $this->generator->resolvePhpVersions('>=8.3'));

        // 8.2.* supports 8.2 only
        $this->assertSame(['8.2'], $this->generator->resolvePhpVersions('8.2.*'));

        // ^8.2|^8.3 supports 8.2, 8.3, 8.4
        $this->assertSame(['8.2', '8.3', '8.4'], $this->generator->resolvePhpVersions('^8.2|^8.3'));

        // Exact match
        $this->assertSame(['8.4'], $this->generator->resolvePhpVersions('8.4'));
    }

    public function test_resolves_laravel_versions_correctly(): void
    {
        $this->assertSame(['11.*', '12.*', '13.*'], $this->generator->resolveLaravelVersions(null));
        $this->assertSame(['11.*', '12.*', '13.*'], $this->generator->resolveLaravelVersions('^11.0|^12.0|^13.0'));
        $this->assertSame(['12.*', '13.*'], $this->generator->resolveLaravelVersions('^12.0|^13.0'));
        $this->assertSame(['13.*'], $this->generator->resolveLaravelVersions('^13.0'));
    }

    public function test_generates_package_matrix_excluding_incompatible_combinations(): void
    {
        $packageDir = $this->tempDir.'/test-pkg';
        File::ensureDirectoryExists($packageDir);
        File::put($packageDir.'/composer.json', json_encode([
            'name' => 'acme/test-pkg',
            'require' => [
                'php' => '^8.2',
                'illuminate/support' => '^11.0|^12.0|^13.0',
            ],
        ]));

        $matrix = $this->generator->generatePackageMatrix($packageDir);

        $this->assertSame(['8.2', '8.3', '8.4'], $matrix['php']);
        $this->assertSame(['11.*', '12.*', '13.*'], $matrix['laravel']);
        $this->assertNotEmpty($matrix['include']);

        // Laravel 13 requires PHP >= 8.3, so PHP 8.2 with Laravel 13.* should be excluded
        $this->assertArrayHasKey('exclude', $matrix);
        $this->assertContains([
            'php' => '8.2',
            'laravel' => '13.*',
        ], $matrix['exclude']);
    }

    public function test_generates_valid_package_workflow_yaml(): void
    {
        $packageDir = $this->tempDir.'/test-yaml-pkg';
        File::ensureDirectoryExists($packageDir);
        File::put($packageDir.'/composer.json', json_encode([
            'name' => 'acme/test-yaml-pkg',
            'require' => [
                'php' => '^8.3',
                'illuminate/support' => '^12.0|^13.0',
            ],
        ]));

        $yaml = $this->generator->generatePackageWorkflowYaml($packageDir);

        $this->assertNotEmpty($yaml);
        $parsed = Yaml::parse($yaml);
        $this->assertIsArray($parsed);
        $this->assertSame('run-tests', $parsed['name']);
        $this->assertArrayHasKey('jobs', $parsed);
        $this->assertArrayHasKey('test', $parsed['jobs']);

        $matrix = $parsed['jobs']['test']['strategy']['matrix'];
        $this->assertSame(['8.3', '8.4'], $matrix['php']);
        $this->assertSame(['12.*', '13.*'], $matrix['laravel']);
    }
}
