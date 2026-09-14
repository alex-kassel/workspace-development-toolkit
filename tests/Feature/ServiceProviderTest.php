<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature;

use AlexKassel\WorkspaceDevelopmentToolkit\Services\SkillInstaller;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use AlexKassel\WorkspaceDevelopmentToolkit\WorkspaceDevelopmentToolkitServiceProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;

class ServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $reflector = new \ReflectionClass(WorkspaceDevelopmentToolkitServiceProvider::class);
        $providerDir = dirname($reflector->getFileName());
        $configSrc = $providerDir.'/../config/workspace.php';
        $stubsSrc = $providerDir.'/../stubs/package';

        /** @var SkillInstaller $installer */
        $installer = $this->app->make(SkillInstaller::class);
        $skillsSource = $installer->getDefaultSourcePath();
        $discoveredSkills = $installer->discoverSkillsInPath($skillsSource);
        $skillsPublishMap = [];
        $targetSkillsBase = $installer->detectSkillsDirectory();

        foreach ($discoveredSkills as $slug => $sourceDir) {
            $skillMd = $sourceDir.DIRECTORY_SEPARATOR.'SKILL.md';
            if ($installer->isPublished($skillMd)) {
                $skillsPublishMap[$sourceDir] = $targetSkillsBase.DIRECTORY_SEPARATOR.$slug;
            }
        }

        ServiceProvider::$publishes[WorkspaceDevelopmentToolkitServiceProvider::class] = array_merge([
            $configSrc => config_path('workspace.php'),
            $stubsSrc => base_path('stubs/workspace'),
        ], $skillsPublishMap);

        ServiceProvider::$publishGroups['workspace-config'] = [
            $configSrc => config_path('workspace.php'),
        ];
        ServiceProvider::$publishGroups['workspace-stubs'] = [
            $stubsSrc => base_path('stubs/workspace'),
        ];
        ServiceProvider::$publishGroups['workspace-skills'] = $skillsPublishMap;
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
        $this->assertFileExists($targetDir.'/.gitattributes.stub');
        $this->assertFileExists($targetDir.'/.gitignore.stub');
        $this->assertFileExists($targetDir.'/tests/TestCase.php.stub');
        $this->assertFileExists($targetDir.'/tests/bootstrap.php.stub');
        $this->assertFileExists($targetDir.'/LICENSE.stub');
        $this->assertFileExists($targetDir.'/composer.json.stub');
        $this->assertFileExists($targetDir.'/src/{{ providerClass }}.php.stub');
        $this->assertFileExists($targetDir.'/config/{{ package }}.php.stub');
        $this->assertFileExists($targetDir.'/tests/Unit/ExampleTest.php.stub');
        $this->assertFileExists($targetDir.'/resources/boost/skills/{{ skillSlug }}/SKILL.md.stub');
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

    public function test_it_publishes_workspace_skills(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'workspace-skills'])
            ->assertSuccessful();

        $this->assertFileExists(base_path('.agents/skills/package-docs/SKILL.md'));
        $this->assertFileExists(base_path('.agents/skills/package-release/SKILL.md'));
        $this->assertFileExists(base_path('.agents/skills/package-scaffolding/SKILL.md'));
        $this->assertFileExists(base_path('.agents/skills/package-verification/SKILL.md'));
    }
}
