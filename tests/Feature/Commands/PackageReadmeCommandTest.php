<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;

class PackageReadmeCommandTest extends TestCase
{
    public function test_package_readme_passes_for_compliant_package(): void
    {
        Workspace::add('packages', null, true);
        $dir = $this->tempDir.'/packages/acme/good-pkg';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/composer.json', json_encode(['name' => 'acme/good-pkg']));
        File::put($dir.'/README.md', "# Good Pkg\n\n## Requirements\n\n## Installation\n\n## Usage\n\n## Testing\n\n## License\n");

        $this->artisan('package:readme', ['package' => 'acme/good-pkg'])
            ->expectsOutputToContain('README verification passed')
            ->assertSuccessful();
    }

    public function test_package_readme_outputs_json(): void
    {
        Workspace::add('packages', null, true);
        $dir = $this->tempDir.'/packages/acme/json-pkg';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/composer.json', json_encode(['name' => 'acme/json-pkg']));

        $this->artisan('package:readme', ['package' => 'acme/json-pkg', '--json' => true])
            ->expectsOutputToContain('file_exists')
            ->assertFailed();
    }
}
