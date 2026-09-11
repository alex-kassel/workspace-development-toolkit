<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceManager;
use Illuminate\Support\Facades\File;
use Tests\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected string $tempDir;

    protected string $originalBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wdt_test_'.uniqid();
        File::ensureDirectoryExists($this->tempDir);

        // Seed an isolated composer.json in the temp sandbox
        $initialComposer = [
            'name' => 'test/app',
            'type' => 'project',
            'require' => [
                'php' => '^8.2',
            ],
            'require-dev' => (object) [],
            'repositories' => (object) [],
        ];
        File::put(
            $this->tempDir.DIRECTORY_SEPARATOR.'composer.json',
            json_encode($initialComposer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        // Seed an empty .gitignore
        File::put($this->tempDir.DIRECTORY_SEPARATOR.'.gitignore', "# test ignore\n");

        // Set up isolated WorkspaceManager pointing to sandbox
        $this->originalBasePath = base_path();
        $this->app->setBasePath($this->tempDir);

        // Re-bind singleton to ensure fresh instance
        $this->app->singleton(WorkspaceManager::class, fn () => new WorkspaceManager);
        Workspace::clearResolvedInstances();
    }

    protected function tearDown(): void
    {
        $this->app->setBasePath($this->originalBasePath);
        $this->app->singleton(WorkspaceManager::class, fn () => new WorkspaceManager);
        Workspace::clearResolvedInstances();

        if (isset($this->tempDir) && File::isDirectory($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    /**
     * Helper to read the sandbox composer.json.
     *
     * @return array<string, mixed>
     */
    protected function getSandboxComposer(): array
    {
        $content = File::get($this->tempDir.DIRECTORY_SEPARATOR.'composer.json');

        return json_decode($content, true) ?: [];
    }

    /**
     * Helper to read the sandbox workspace.json.
     *
     * @return array<string, mixed>
     */
    protected function getSandboxWorkspace(): array
    {
        $path = $this->tempDir.DIRECTORY_SEPARATOR.'workspace.json';
        if (! File::exists($path)) {
            return [];
        }

        return json_decode(File::get($path), true) ?: [];
    }

    /**
     * Helper to create a dummy package in the sandbox.
     */
    protected function createDummyPackage(string $relativeDir, string $composerName): void
    {
        $fullPath = $this->tempDir.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativeDir);
        File::ensureDirectoryExists($fullPath.'/src');

        $composer = [
            'name' => $composerName,
            'type' => 'library',
            'autoload' => [
                'psr-4' => [
                    'TestDummy\\' => 'src/',
                ],
            ],
        ];

        File::put(
            $fullPath.DIRECTORY_SEPARATOR.'composer.json',
            json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }
}
