<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature;

use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;

class ServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $reflector = new \ReflectionClass(\AlexKassel\WorkspaceDevelopmentToolkit\WorkspaceDevelopmentToolkitServiceProvider::class);
        $providerDir = dirname($reflector->getFileName());
        $configSrc = $providerDir.'/../config/workspace.php';
        $stubsSrc = $providerDir.'/../stubs/package';

        \Illuminate\Support\ServiceProvider::$publishes[\AlexKassel\WorkspaceDevelopmentToolkit\WorkspaceDevelopmentToolkitServiceProvider::class] = [
            $configSrc => config_path('workspace.php'),
            $stubsSrc => base_path('stubs/workspace'),
        ];
        \Illuminate\Support\ServiceProvider::$publishGroups['workspace-config'] = [
            $configSrc => config_path('workspace.php'),
        ];
        \Illuminate\Support\ServiceProvider::$publishGroups['workspace-stubs'] = [
            $stubsSrc => base_path('stubs/workspace'),
        ];
    }

    public function test_it_publishes_workspace_stubs(): void
    {
        $targetDir = base_path('stubs/workspace');
        if (File::isDirectory($targetDir)) {
            File::deleteDirectory($targetDir);
        }

        $this->artisan('vendor:publish', ['--tag' => 'workspace-stubs'])
            ->assertSuccessful();

        $this->assertDirectoryExists($targetDir);
        $this->assertFileExists($targetDir.'/CHANGELOG.md.stub');
        $this->assertFileExists($targetDir.'/README.md.stub');
        $this->assertFileExists($targetDir.'/phpunit.xml.stub');
        $this->assertFileExists($targetDir.'/phpstan.neon.stub');
        $this->assertFileExists($targetDir.'/gitattributes.stub');
        $this->assertFileExists($targetDir.'/gitignore.stub');
        $this->assertFileExists($targetDir.'/TestCase.php.stub');
        $this->assertFileExists($targetDir.'/bootstrap.php.stub');
    }

    public function test_it_publishes_workspace_config(): void
    {
        $targetFile = config_path('workspace.php');
        if (File::exists($targetFile)) {
            File::delete($targetFile);
        }

        $this->artisan('vendor:publish', ['--tag' => 'workspace-config'])
            ->assertSuccessful();

        $this->assertFileExists($targetFile);
    }
}
