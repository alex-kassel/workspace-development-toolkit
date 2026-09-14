<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature\Chaos;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\Support\Chaos\FilesystemSnapshot;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;

class FileSystemBoundaryChaosTest extends TestCase
{
    public function test_destructive_commands_cannot_delete_or_mutate_protected_roots_and_alien_files(): void
    {
        // 1. Setup workspace with two packages
        Workspace::add('packages', null, true);
        $this->createDummyPackage('packages/acme/target-pkg', 'acme/target-pkg');
        $this->createDummyPackage('packages/acme/protected-pkg', 'acme/protected-pkg');

        // 2. Plant honey-pot files in root and outside
        $rootEnv = base_path('.env');
        File::put($rootEnv, "APP_KEY=secret_key_12345\nAPP_ENV=local\n");

        $rootReadme = base_path('README.md');
        File::put($rootReadme, "# Main Application\n");

        $alienDir = base_path('packages/alien-folder');
        File::ensureDirectoryExists($alienDir);
        File::put($alienDir.'/data.txt', 'alien unmanaged content');

        Workspace::sync();

        // 3. Capture snapshot of all protected assets
        $snapshot = new FilesystemSnapshot([
            $rootEnv,
            $rootReadme,
            base_path('composer.json'),
            base_path('.gitignore'),
            base_path('packages/acme/protected-pkg'),
            $alienDir,
        ]);

        // 4. Run barrage of destructive attacks
        $attacks = [
            // Attempting to delete dot / current directory
            ['command' => 'package:delete', 'args' => ['package' => '.', '--force' => true]],
            // Attempting to delete workspace directory itself
            ['command' => 'package:delete', 'args' => ['package' => 'packages', '--force' => true]],
            // Attempting traversal
            ['command' => 'package:delete', 'args' => ['package' => '../../', '--force' => true]],
            ['command' => 'package:delete', 'args' => ['package' => '../packages', '--force' => true]],
            ['command' => 'package:delete', 'args' => ['package' => '/etc/passwd', '--force' => true]],
            // Attempting to delete alien directory not registered as package
            ['command' => 'package:delete', 'args' => ['package' => 'alien-folder', '--force' => true]],
            ['command' => 'package:delete', 'args' => ['package' => 'packages/alien-folder', '--force' => true]],
            // Attempting invalid workspace removal
            ['command' => 'workspace:remove', 'args' => ['path' => '.']],
            ['command' => 'workspace:remove', 'args' => ['path' => '/']],
            ['command' => 'workspace:remove', 'args' => ['path' => '../../']],
            ['command' => 'workspace:remove', 'args' => ['path' => 'ghost-workspace']],
        ];

        foreach ($attacks as $attack) {
            $this->artisan($attack['command'], $attack['args'])->assertFailed();

            // Verify inviolability after every single attack
            $snapshot->verifyUntouched();
        }

        // 5. Deleting legitimate target package succeeds without touching protected snapshot
        $this->artisan('package:delete', ['package' => 'acme/target-pkg', '--force' => true])
            ->assertSuccessful();

        $this->assertDirectoryDoesNotExist(base_path('packages/acme/target-pkg'));

        // Protected files must STILL be completely untouched
        $snapshot->verifyUntouched();
    }
}
