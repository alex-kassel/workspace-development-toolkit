<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;

class WorkspaceStubsCommandTest extends TestCase
{
    public function test_publishes_stubs_to_workspace_folder(): void
    {
        $wsDir = base_path('test_pub_ws');
        File::ensureDirectoryExists($wsDir);

        try {
            $this->artisan('workspace:stubs', [
                '--workspace' => 'test_pub_ws',
                '--archetype' => 'minimal',
            ])->assertSuccessful();

            $this->assertDirectoryExists("{$wsDir}/.stubs");
            $this->assertFileExists("{$wsDir}/.stubs/composer.json.stub");
            $this->assertFileExists("{$wsDir}/.stubs/stubs.json");
        } finally {
            File::deleteDirectory($wsDir);
        }
    }
}
