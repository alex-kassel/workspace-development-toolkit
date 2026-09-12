<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Feature;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\CheckResult;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\FilesystemHelper;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\PackageScaffolder;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\PackageVerifier;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ReleaseChecker;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceManager;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Throwable;

final class AuditFindingsRegressionTest extends TestCase
{
    public function test_f02_standalone_rejects_option_from_url_and_template(): void
    {
        $stub = dirname(__DIR__, 2).'/stubs/workspace.stub';
        foreach (['url', 'template'] as $source) {
            $dir = base_path($source);
            File::ensureDirectoryExists($dir);
            File::copy($stub, $dir.'/workspace');
            $entry = ['name' => 'acme/one'];
            $manifest = ['repository_template' => 'https://example.invalid/{package}', 'workspaces' => ['packages' => ['vendor' => null, 'packages' => [$entry]]]];
            if ($source === 'url') {
                $manifest['workspaces']['packages']['packages'][0]['url'] = '--upload-pack=echo WDT_HARMLESS';
            } else {
                $manifest['repository_template'] = '--upload-pack=echo WDT_HARMLESS';
            }
            File::put($dir.'/workspace.json', json_encode($manifest));
            $process = new SymfonyProcess([PHP_BINARY, '-n', $dir.'/workspace', 'restore'], $dir);
            $process->run();
            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('[INVALID URL]', $process->getErrorOutput());
            $this->assertDirectoryDoesNotExist($dir.'/packages');
        }
    }

    public function test_f02_supported_schemes_reach_git_as_positional_arguments(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows command boundary test');
        }
        $stub = dirname(__DIR__, 2).'/stubs/workspace.stub';
        File::copy($stub, base_path('workspace'));
        File::put(base_path('git.bat'), "@echo off\r\necho %*>>arguments.txt\r\nexit /b 1\r\n");
        $urls = ['https://example.invalid/a', 'http://example.invalid/a', 'git@example.invalid:a/b', 'ssh://example.invalid/a', 'git://example.invalid/a'];
        $entries = [];
        foreach ($urls as $index => $url) {
            $entries[] = ['name' => 'acme/pkg'.$index, 'url' => $url];
        }
        File::put(base_path('workspace.json'), json_encode(['workspaces' => ['packages' => ['packages' => $entries]]]));
        $process = new SymfonyProcess([PHP_BINARY, '-n', base_path('workspace'), 'restore'], base_path());
        $process->run();
        $this->assertSame(1, $process->getExitCode());
        $arguments = File::get(base_path('arguments.txt'));
        foreach ($urls as $url) {
            $this->assertStringContainsString('clone -- "'.$url.'"', $arguments);
        }
    }

    public function test_f01_dot_segment_workspace_root_survives(): void
    {
        Workspace::add('labs/./area', 'acme', true);
        $this->createDummyPackage('labs/area/one', 'acme/one');
        Workspace::sync();
        $this->artisan('package:delete', ['name' => 'acme/one', '--force' => true])->assertSuccessful();
        $this->assertDirectoryExists(base_path('labs/area'));
    }

    public function test_f01_package_containing_registered_child_workspace_survives(): void
    {
        Workspace::add('labs', 'acme', true);
        $this->createDummyPackage('labs/one', 'acme/one');
        Workspace::add('labs/one/children', 'child');
        File::put(base_path('labs/one/children/.gitkeep'), 'keep');
        Workspace::sync();
        $this->artisan('package:delete', ['name' => 'acme/one', '--force' => true])->run();
        $this->assertFileExists(base_path('labs/one/children/.gitkeep'));
    }

    public function test_f01_hidden_entries_and_non_last_package_survive(): void
    {
        Workspace::add('packages');
        $this->createDummyPackage('packages/acme/one', 'acme/one');
        $this->createDummyPackage('packages/acme/two', 'acme/two');
        File::put(base_path('packages/acme/.gitkeep'), 'keep');
        File::ensureDirectoryExists(base_path('packages/acme/.git'));
        Workspace::sync();
        $this->artisan('package:delete', ['name' => 'acme/one', '--force' => true])->assertSuccessful();
        $this->assertFileExists(base_path('packages/acme/two/composer.json'));
        $this->artisan('package:delete', ['name' => 'acme/two', '--force' => true])->assertSuccessful();
        $this->assertFileExists(base_path('packages/acme/.gitkeep'));
        $this->assertDirectoryExists(base_path('packages/acme/.git'));
    }

    public function test_f01_failed_physical_deletion_preserves_workspace_registration(): void
    {
        Workspace::add('packages');
        $this->createDummyPackage('packages/acme/failing', 'acme/failing');
        Workspace::recordPackage('packages', 'acme/failing', null, 'https://example.invalid/failing');
        Workspace::sync();

        $beforeWorkspace = File::get(base_path('workspace.json'));

        $this->mock(FilesystemHelper::class, function ($mock): void {
            $mock->shouldReceive('deleteDirectoryRecursively')->andReturn(false);
            $mock->shouldReceive('isLinkOrJunction')->andReturn(false);
        });

        $this->app->forgetInstance(WorkspaceManager::class);
        Workspace::clearResolvedInstances();

        $this->artisan('package:delete', ['name' => 'acme/failing', '--force' => true])
            ->assertFailed();

        $afterWorkspace = File::get(base_path('workspace.json'));
        $this->assertSame($beforeWorkspace, $afterWorkspace);
        $this->assertContains('acme/failing', array_column(Workspace::all()['packages']['packages'], 'name'));
        $this->assertDirectoryExists(base_path('packages/acme/failing'));
    }

    public function test_f01_exception_during_physical_deletion_preserves_workspace_registration(): void
    {
        Workspace::add('packages');
        $this->createDummyPackage('packages/acme/failing-ex', 'acme/failing-ex');
        Workspace::recordPackage('packages', 'acme/failing-ex', null, 'https://example.invalid/failing-ex');
        Workspace::sync();

        $beforeWorkspace = File::get(base_path('workspace.json'));

        $this->mock(FilesystemHelper::class, function ($mock): void {
            $mock->shouldReceive('deleteDirectoryRecursively')
                ->andThrow(new RuntimeException('Disk I/O error or permission denied'));
            $mock->shouldReceive('isLinkOrJunction')->andReturn(false);
        });

        $this->app->forgetInstance(WorkspaceManager::class);
        Workspace::clearResolvedInstances();

        $this->artisan('package:delete', ['name' => 'acme/failing-ex', '--force' => true])
            ->assertFailed();

        $afterWorkspace = File::get(base_path('workspace.json'));
        $this->assertSame($beforeWorkspace, $afterWorkspace);
        $this->assertContains('acme/failing-ex', array_column(Workspace::all()['packages']['packages'], 'name'));
        $this->assertDirectoryExists(base_path('packages/acme/failing-ex'));
    }

    public function test_f03_name_collision_is_byte_atomic(): void
    {
        Workspace::add('labs', 'acme', true);
        Workspace::recordPackage('labs', 'one', null, 'https://example.invalid/one');
        Workspace::recordPackage('labs', 'two');
        $before = File::get(base_path('workspace.json'));
        try {
            Workspace::registerPackageAlias('labs', 'two', 'one');
            $this->fail('Expected a collision exception');
        } catch (WorkspaceException) {
        }
        $this->assertSame($before, File::get(base_path('workspace.json')));
    }

    public function test_f03_existing_alias_collision_is_rejected_atomically(): void
    {
        Workspace::add('labs', 'acme', true);
        Workspace::recordPackage('labs', 'one', 'Shared');
        Workspace::recordPackage('labs', 'two');
        $before = File::get(base_path('workspace.json'));
        try {
            Workspace::registerPackageAlias('labs', 'two', 'Shared');
        } catch (WorkspaceException) {
        }
        $this->assertSame($before, File::get(base_path('workspace.json')));
    }

    public function test_f03_self_alias_is_repeatable_and_preserves_metadata(): void
    {
        Workspace::add('labs', 'acme', true);
        Workspace::recordPackage('labs', 'one', null, 'https://example.invalid/one');
        Workspace::updatePackageSkills('labs', 'one', ['sample']);
        Workspace::registerPackageAlias('labs', 'one', 'one');
        $before = File::get(base_path('workspace.json'));
        Workspace::registerPackageAlias('labs', 'one', 'one');
        $this->assertSame($before, File::get(base_path('workspace.json')));
        $this->assertSame(['sample'], Workspace::all()['labs']['packages'][0]['skills']);
    }

    public function test_f05_real_junction_with_spaces_and_ampersand_and_retry(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows junction test');
        }
        $helper = app(FilesystemHelper::class);
        $target = base_path('target space & more');
        $link = base_path('vendor space & more/link');
        File::ensureDirectoryExists($target);
        File::put($target.'/marker', 'ok');
        $helper->updateSymlinkOrJunction($link, $target);
        try {
            $this->assertSame('ok', File::get($link.'/marker'));
            $helper->updateSymlinkOrJunction($link, $target);
            $this->assertSame('ok', File::get($link.'/marker'));
        } finally {
            rmdir($link);
        }
    }

    public function test_f05_real_failure_does_not_report_success(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows junction test');
        }
        $link = base_path('vendor/acme/one');
        File::ensureDirectoryExists($link);
        File::put($link.'/occupied', 'untouched');
        try {
            app(FilesystemHelper::class)->updateSymlinkOrJunction($link, base_path('target'));
            $this->fail('mklink must fail for an occupied directory');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Failed to create directory junction', $e->getMessage());
        }
        $this->assertSame('untouched', File::get($link.'/occupied'));
    }

    public function test_f06_git_failure_restores_all_host_files(): void
    {
        Workspace::add('packages', null, true);
        $before = $this->hostSnapshot();
        Process::fake(fn ($process) => Process::result('', 'controlled', str_contains(implode(' ', (array) $process->command), 'git init') ? 128 : 0));
        try {
            app(PackageScaffolder::class)->scaffold('packages', 'acme/one');
            $this->fail('Expected git init failure');
        } catch (WorkspaceException) {
        }
        $this->assertDirectoryDoesNotExist(base_path('packages/acme/one'));
        $this->assertSame($before, $this->hostSnapshot());
    }

    public function test_f06_sync_failure_removes_partial_package(): void
    {
        Workspace::add('packages', null, true);
        File::put(base_path('composer.json'), '{ invalid original host manifest');
        $before = $this->hostSnapshot();
        Process::fake(['*' => Process::result('ok')]);
        try {
            app(PackageScaffolder::class)->scaffold('packages', 'acme/one');
            $this->fail('Expected sync failure');
        } catch (WorkspaceException) {
        }
        $this->assertSame($before, $this->hostSnapshot());
        $this->assertDirectoryDoesNotExist(base_path('packages/acme/one'));
    }

    public function test_f06_git_tag_failure_restores_offline_entry(): void
    {
        Workspace::add('labs', 'acme', true);
        Workspace::recordPackage('labs', 'one', null, 'https://example.invalid/custom');
        Workspace::updatePackageSkills('labs', 'one', ['original']);
        Workspace::sync();
        $before = $this->hostSnapshot();
        Process::fake(fn ($process) => Process::result('ok', 'controlled', str_contains(implode(' ', (array) $process->command), 'git tag') ? 128 : 0));
        try {
            app(PackageScaffolder::class)->scaffold('labs', 'one');
            $this->fail('Expected git tag failure');
        } catch (WorkspaceException) {
        }
        $this->assertDirectoryDoesNotExist(base_path('labs/one'));
        $this->assertSame($before, $this->hostSnapshot());
    }

    public function test_f07_failed_link_replacement_restores_existing_vendor_link(): void
    {
        Workspace::add('labs', 'acme', true);
        $this->createDummyPackage('labs/one', 'acme/one');
        Workspace::sync();

        $data = ['packages' => [['name' => 'acme/one', 'dist' => ['type' => 'path', 'url' => 'labs/one']]]];
        File::put(base_path('composer.lock'), json_encode($data));
        File::ensureDirectoryExists(base_path('vendor/composer'));
        File::put(base_path('vendor/composer/installed.json'), json_encode($data));
        File::put(base_path('vendor/composer/installed.php'), '<?php return [];');

        $vendorLink = base_path('vendor/acme/one');
        $newTarget = base_path('labs/Renamed');
        $oldTarget = base_path('labs/one');

        $replacementCalls = [];
        $this->mock(FilesystemHelper::class, function ($mock) use ($vendorLink, $newTarget, $oldTarget, &$replacementCalls): void {
            // Report the initial vendor path as an existing link/junction
            $mock->shouldReceive('isLinkOrJunction')
                ->with($vendorLink)
                ->andReturn(true);

            // 1. Attempt to set new link -> fails
            // 2. Rollback attempt to restore old link -> succeeds
            $mock->shouldReceive('updateSymlinkOrJunction')
                ->atLeast()->twice()
                ->andReturnUsing(function (string $link, string $target) use (&$replacementCalls, $newTarget, $oldTarget): void {
                    $replacementCalls[] = ['link' => $link, 'target' => $target];

                    if (count($replacementCalls) === 1) {
                        $this->assertSame($newTarget, $target);
                        throw new RuntimeException('Controlled failure creating new vendor link');
                    }

                    $this->assertSame($oldTarget, $target);
                });
        });

        $this->app->forgetInstance(WorkspaceManager::class);
        Workspace::clearResolvedInstances();

        try {
            Workspace::aliasPackage('one', 'Renamed');
            $this->fail('Expected WorkspaceException on link failure');
        } catch (WorkspaceException $e) {
            $this->assertStringContainsString('Controlled failure creating new vendor link', $e->getMessage());
            $this->assertNotNull($e->getPrevious());
            $this->assertSame('Controlled failure creating new vendor link', $e->getPrevious()->getMessage());
        }

        clearstatcache();
        $this->assertDirectoryExists(base_path('labs/one'));
        $this->assertDirectoryDoesNotExist(base_path('labs/Renamed'));
        $this->assertSame($data, json_decode(File::get(base_path('composer.lock')), true));
        $this->assertSame($data, json_decode(File::get(base_path('vendor/composer/installed.json')), true));

        // Verify that rollback explicitly requested restoring the old vendor link to the old directory
        $this->assertCount(2, $replacementCalls);
        $this->assertSame(['link' => $vendorLink, 'target' => $newTarget], $replacementCalls[0]);
        $this->assertSame(['link' => $vendorLink, 'target' => $oldTarget], $replacementCalls[1]);
    }

    public function test_f07_failure_after_removing_old_link_restores_it(): void
    {
        Workspace::add('labs', 'acme', true);
        $this->createDummyPackage('labs/one', 'acme/one');
        Workspace::sync();
        $link = base_path('vendor/acme/one');
        File::ensureDirectoryExists(dirname($link));
        File::put($link, 'old link sentinel');
        $calls = 0;
        $this->mock(FilesystemHelper::class, function ($mock) use ($link, &$calls): void {
            $mock->shouldReceive('isLinkOrJunction')->andReturnUsing(fn ($p) => is_link($p) || @readlink($p) !== false);
            $mock->shouldReceive('updateSymlinkOrJunction')->atLeast()->once()->andReturnUsing(function ($l, $target) use ($link, &$calls): void {
                $calls++;
                if ($calls === 1) {
                    unlink($link);
                    throw new RuntimeException('Controlled failure after removing old vendor entry');
                }
                // Rollback invocation: recreate the sentinel file/link
                File::put($link, 'old link sentinel');
            });
        });
        $this->app->forgetInstance(WorkspaceManager::class);
        Workspace::clearResolvedInstances();
        try {
            Workspace::aliasPackage('one', 'Renamed');
            $this->fail('Expected replacement failure');
        } catch (WorkspaceException) {
        }
        $this->assertDirectoryExists(base_path('labs/one'));
        $this->assertFileExists($link);
    }

    public function test_f07_rollback_rename_failure_is_not_reported_as_restored(): void
    {
        Workspace::add('labs', 'acme', true);
        $this->createDummyPackage('labs/one', 'acme/one');
        Workspace::sync();
        $this->mock(FilesystemHelper::class, function ($mock): void {
            $mock->shouldReceive('isLinkOrJunction')->andReturnUsing(fn ($p) => is_link($p) || @readlink($p) !== false);
            $mock->shouldReceive('updateSymlinkOrJunction')->once()->andReturnUsing(function (): void {
                File::ensureDirectoryExists(base_path('labs/one'));
                File::put(base_path('labs/one/blocker'), 'concurrent occupant');
                throw new RuntimeException('Controlled concurrent rename blocker');
            });
        });
        $this->app->forgetInstance(WorkspaceManager::class);
        Workspace::clearResolvedInstances();
        try {
            Workspace::aliasPackage('one', 'Renamed');
            $this->fail('Expected failure');
        } catch (WorkspaceException $e) {
            $this->assertStringNotContainsString('was rolled back', $e->getSolution());
        }
        $this->assertFileExists(base_path('labs/Renamed/composer.json'));
        $this->assertFileExists(base_path('labs/one/blocker'));
    }

    public function test_f07_vendor_link_restore_failure_is_reported_as_incomplete_rollback(): void
    {
        Workspace::add('labs', 'acme', true);
        $this->createDummyPackage('labs/one', 'acme/one');
        Workspace::sync();

        $calls = 0;
        $this->mock(FilesystemHelper::class, function ($mock) use (&$calls): void {
            $mock->shouldReceive('isLinkOrJunction')->andReturnUsing(fn ($p) => true);
            $mock->shouldReceive('updateSymlinkOrJunction')->atLeast()->twice()->andReturnUsing(function ($link, $target) use (&$calls): void {
                $calls++;
                if ($calls === 1) {
                    throw new RuntimeException('Failed to set new vendor link');
                }
                throw new RuntimeException('Failed to restore original vendor link');
            });
        });

        $this->app->forgetInstance(WorkspaceManager::class);
        Workspace::clearResolvedInstances();

        try {
            Workspace::aliasPackage('one', 'Renamed');
            $this->fail('Expected failure');
        } catch (WorkspaceException $e) {
            $this->assertStringContainsString('Rollback was incomplete: vendor link could not be restored', $e->getMessage());
            $this->assertStringNotContainsString('The directory rename was rolled back.', $e->getSolution());
            $this->assertNotNull($e->getPrevious());
            $this->assertSame('Failed to set new vendor link', $e->getPrevious()->getMessage());
        }

        // Directory was restored, but vendor link failed
        $this->assertDirectoryExists(base_path('labs/one'));
        $this->assertDirectoryDoesNotExist(base_path('labs/Renamed'));
    }

    public function test_f06_render_failure_does_not_leave_partial_directory(): void
    {
        Workspace::add('packages', null, true);
        File::ensureDirectoryExists(base_path('stubs/workspace/composer.json'));
        File::put(base_path('stubs/workspace/composer.json.stub'), '{}');
        File::put(base_path('stubs/workspace/composer.json/child.stub'), 'unused');
        Process::fake(['*' => Process::result('ok')]);
        $failure = null;
        try {
            app(PackageScaffolder::class)->scaffold('packages', 'acme/one');
        } catch (Throwable $e) {
            $failure = $e;
        }
        $this->assertNotNull($failure);
        $this->assertDirectoryDoesNotExist(base_path('packages/acme/one'));
    }

    #[DataProvider('gitFailureStages')]
    public function test_f06_each_git_stage_rolls_back_and_allows_retry(string $stage): void
    {
        Workspace::add('packages', null, true);
        Workspace::sync();
        $before = $this->hostSnapshot();
        Process::fake(fn ($process) => Process::result('ok', 'controlled', str_contains(implode(' ', (array) $process->command), $stage) ? 128 : 0));
        try {
            app(PackageScaffolder::class)->scaffold('packages', 'acme/one');
            $this->fail('Expected '.$stage.' failure');
        } catch (WorkspaceException) {
        }
        $this->assertDirectoryDoesNotExist(base_path('packages/acme/one'));
        $this->assertSame($before, $this->hostSnapshot());
        Process::fake(['*' => Process::result('ok')]);
        app(PackageScaffolder::class)->scaffold('packages', 'acme/one');
        $this->assertFileExists(base_path('packages/acme/one/composer.json'));
    }

    /** @return array<string, array{string}> */
    public static function gitFailureStages(): array
    {
        return ['init' => ['git init'], 'add' => ['git add'], 'commit' => ['git commit'], 'tag' => ['git tag']];
    }

    public function test_f06_readonly_cleanup_failure_is_not_claimed_as_rollback(): void
    {
        Workspace::add('packages', null, true);
        Workspace::sync();
        $manifest = base_path('packages/acme/one/composer.json');
        Process::fake(function ($process) use ($manifest) {
            if (str_contains(implode(' ', (array) $process->command), 'git init')) {
                chmod($manifest, 0444);

                return Process::result('', 'controlled', 128);
            }

            return Process::result('ok');
        });
        try {
            try {
                app(PackageScaffolder::class)->scaffold('packages', 'acme/one');
                $this->fail('Expected failure');
            } catch (WorkspaceException) {
            }
            $this->assertDirectoryDoesNotExist(base_path('packages/acme/one'));
        } finally {
            if (file_exists($manifest)) {
                chmod($manifest, 0644);
            }
        }
    }

    public function test_f05_junction_retry_after_clearing_stat_cache(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows junction test');
        }
        $helper = app(FilesystemHelper::class);
        $target = base_path('target');
        $link = base_path('vendor/link');
        File::ensureDirectoryExists($target);
        $helper->updateSymlinkOrJunction($link, $target);
        clearstatcache();
        try {
            $helper->updateSymlinkOrJunction($link, $target);
            clearstatcache();
            $this->assertTrue($helper->isLinkOrJunction($link));
        } finally {
            if ($helper->isLinkOrJunction($link)) {
                rmdir($link);
            }
        }
    }

    public function test_f08_good_bad_good_preserves_registered_metadata_and_good_operations(): void
    {
        Workspace::add('packages');
        foreach (['a', 'b', 'c'] as $name) {
            $this->createDummyPackage('packages/acme/'.$name, 'acme/'.$name);
        }
        Workspace::recordPackage('packages', 'acme/b', null, 'https://example.invalid/b');
        Workspace::sync();
        File::put(base_path('packages/acme/b/composer.json'), '{ invalid');
        Workspace::sync();
        $this->assertSame('packages/acme/a', Workspace::findPackagePath('acme/a'));
        $this->assertSame('packages/acme/c', Workspace::findPackagePath('acme/c'));
        $this->artisan('workspace:list')->assertSuccessful();
        $this->artisan('package:delete', ['name' => 'acme/a', '--force' => true])->assertSuccessful();
        $this->assertContains(['name' => 'acme/b', 'url' => 'https://example.invalid/b'], Workspace::all()['packages']['packages']);
        $this->assertFileExists(base_path('packages/acme/b/composer.json'));
    }

    public function test_f08_corruption_is_visible_in_workspace_listing(): void
    {
        Workspace::add('packages');
        $this->createDummyPackage('packages/acme/broken', 'acme/broken');
        Workspace::recordPackage('packages', 'acme/broken', null, 'https://example.invalid/broken');
        Workspace::sync();
        File::put(base_path('packages/acme/broken/composer.json'), '{ invalid json');
        Workspace::clearCache();

        // 1. Resolver index still includes the corrupted package with corrupted status
        $this->assertTrue(Workspace::isPackageCorrupted('acme/broken', 'packages'));
        $this->assertSame('packages/acme/broken', Workspace::findPackagePath('acme/broken', 'packages'));

        // 2. Listing outputs the package with 'corrupted manifest' status
        $this->artisan('workspace:list')
            ->expectsOutputToContain('acme/broken [corrupted manifest')
            ->assertSuccessful();
    }

    public function test_f08_corrupted_package_can_be_removed_and_restored(): void
    {
        Workspace::add('packages');
        $this->createDummyPackage('packages/acme/broken', 'acme/broken');
        Workspace::recordPackage('packages', 'acme/broken', null, 'https://example.invalid/broken');
        Workspace::sync();
        File::put(base_path('packages/acme/broken/composer.json'), '{ invalid json');
        Workspace::clearCache();

        // Targeted deletion removes the corrupted package and its manifest record
        $this->artisan('package:delete', ['name' => 'acme/broken', '--force' => true])->assertSuccessful();
        $this->assertDirectoryDoesNotExist(base_path('packages/acme/broken'));
        $this->assertNull(Workspace::findPackagePath('acme/broken'));

        // Recreation/restoration succeeds cleanly
        $this->createDummyPackage('packages/acme/broken', 'acme/broken');
        Workspace::clearCache();
        Workspace::sync();
        $this->assertFalse(Workspace::isPackageCorrupted('acme/broken', 'packages'));
        $this->assertSame('packages/acme/broken', Workspace::findPackagePath('acme/broken'));
    }

    public function test_f04_failed_certificate_cannot_be_ready(): void
    {
        $this->assertCertificateVerdict(['audit' => ['commit' => '1234567'], 'verdict' => 'FAILED', 'checks' => ['tests' => ['status' => 'failed']]], '0', 'ACTION_REQUIRED');
    }

    public function test_f04_valid_certificate_can_be_ready(): void
    {
        $this->assertCertificateVerdict(['audit' => ['commit' => '1234567'], 'verdict' => 'PASSED'], '0', 'READY');
    }

    public function test_f04_stale_certificate_requires_action(): void
    {
        $this->assertCertificateVerdict(['audit' => ['commit' => '1234567']], '1', 'ACTION_REQUIRED');
    }

    public function test_f04_missing_commit_requires_action(): void
    {
        $this->assertCertificateVerdict([], '0', 'ACTION_REQUIRED');
    }

    public function test_f04_malformed_certificate_requires_action(): void
    {
        $this->assertCertificateVerdict('{ invalid json', '0', 'ACTION_REQUIRED');
    }

    public function test_f04_action_required_certificate_cannot_be_ready(): void
    {
        $this->assertCertificateVerdict(['audit' => ['commit' => '1234567'], 'verdict' => 'ACTION_REQUIRED'], '0', 'ACTION_REQUIRED');
    }

    public function test_f04_missing_verdict_cannot_be_ready(): void
    {
        $this->assertCertificateVerdict(['audit' => ['commit' => '1234567']], '0', 'ACTION_REQUIRED');
    }

    public function test_f04_empty_verdict_cannot_be_ready(): void
    {
        $this->assertCertificateVerdict(['audit' => ['commit' => '1234567'], 'verdict' => ''], '0', 'ACTION_REQUIRED');
    }

    public function test_f04_release_gate_missing_verdict_cannot_be_ready(): void
    {
        Workspace::add('packages');
        $this->createDummyPackage('packages/acme/one', 'acme/one');
        $dir = base_path('packages/acme/one');
        File::ensureDirectoryExists($dir.'/.git');
        File::put($dir.'/RELEASE-GATE.md', "audit commit 1234567\n");
        File::put($dir.'/.gitattributes', '/tests export-ignore');
        File::put($dir.'/README.md', "# One\n\n## Requirements\n\n## Installation\n\n## Usage\n\n## Testing\n\n## License\n");
        $this->mock(PackageVerifier::class, function ($mock): void {
            $mock->shouldReceive('checkAll')->once()->andReturn([new CheckResult('composer', 'acme/one', 'passed', 'ok')]);
        });
        Process::fake(function ($process) {
            $command = implode(' ', (array) $process->command);

            return Process::result(str_contains($command, 'rev-list') ? '0' : (str_contains($command, 'status') ? '' : 'ok'));
        });
        $this->assertSame('ACTION_REQUIRED', app(ReleaseChecker::class)->check('acme/one', fast: true)['verdict']);
    }

    public function test_f05_percent_path_is_not_expanded(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows junction test');
        }
        $helper = app(FilesystemHelper::class);
        putenv('WDT_AUDIT_SEGMENT=expanded');
        $target = base_path('target-%WDT_AUDIT_SEGMENT%');
        $link = base_path('vendor/link-%WDT_AUDIT_SEGMENT%');
        File::ensureDirectoryExists($target);
        try {
            $helper->updateSymlinkOrJunction($link, $target);
            clearstatcache();
            $this->assertTrue($helper->isLinkOrJunction($link), 'Success must create the exact requested link path');
        } finally {
            foreach ([$link, base_path('vendor/link-expanded')] as $candidate) {
                if ($helper->isLinkOrJunction($candidate)) {
                    rmdir($candidate);
                }
            }
            putenv('WDT_AUDIT_SEGMENT');
        }
    }

    /** @param array<string, mixed>|string $audit */
    private function assertCertificateVerdict(array|string $audit, string $delta, string $expected): void
    {
        Workspace::add('packages');
        $this->createDummyPackage('packages/acme/one', 'acme/one');
        $dir = base_path('packages/acme/one');
        File::ensureDirectoryExists($dir.'/.git');
        File::put($dir.'/AUDIT.json', is_string($audit) ? $audit : json_encode($audit));
        File::put($dir.'/.gitattributes', '/tests export-ignore');
        File::put($dir.'/README.md', "# One\n\n## Requirements\n\n## Installation\n\n## Usage\n\n## Testing\n\n## License\n");
        $this->mock(PackageVerifier::class, function ($mock): void {
            $mock->shouldReceive('checkAll')->once()->andReturn([new CheckResult('composer', 'acme/one', 'passed', 'ok')]);
        });
        Process::fake(function ($process) use ($delta) {
            $command = implode(' ', (array) $process->command);

            return Process::result(str_contains($command, 'rev-list') ? $delta : (str_contains($command, 'status') ? '' : 'ok'));
        });
        $this->assertSame($expected, app(ReleaseChecker::class)->check('acme/one', fast: true)['verdict']);
    }

    /** @return array<string, string|null> */
    private function hostSnapshot(): array
    {
        $result = [];
        foreach (['workspace.json', 'composer.json', 'composer.lock', 'workspace', '.gitignore', 'vendor/composer/installed.json', 'vendor/composer/installed.php'] as $path) {
            $result[$path] = File::exists(base_path($path)) ? File::get(base_path($path)) : null;
        }

        return $result;
    }
}
