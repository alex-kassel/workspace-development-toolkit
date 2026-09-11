<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Unit;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\AmbiguousPackageException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\DefaultWorkspaceNotConfiguredException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\InvalidJsonException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\InvalidWorkspacePathException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceNotFoundException;
use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerManager;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;

class WorkspaceManagerTest extends TestCase
{
    public function test_load_syncs_automatically_if_workspace_json_is_missing(): void
    {
        $this->assertFileDoesNotExist(base_path('workspace.json'));

        $data = Workspace::load();

        $this->assertFileExists(base_path('workspace.json'));
        $this->assertArrayHasKey('workspaces', $data);
        $this->assertArrayHasKey('default', $data);
    }

    public function test_load_throws_invalid_json_exception_on_corrupted_file(): void
    {
        File::put(base_path('workspace.json'), '{ invalid json content ...');

        $this->expectException(InvalidJsonException::class);
        Workspace::load();
    }

    public function test_load_throws_invalid_json_exception_when_workspaces_key_is_missing(): void
    {
        File::put(base_path('workspace.json'), json_encode(['default' => null]));

        $this->expectException(InvalidJsonException::class);
        Workspace::load();
    }

    public function test_set_default_updates_default_workspace(): void
    {
        // Add workspace first
        Workspace::add('packages');

        $result = Workspace::setDefault('packages');

        $this->assertTrue($result);
        $this->assertSame('packages', Workspace::getDefault());
        $this->assertSame('packages', Workspace::getRequiredDefault());
    }

    public function test_set_default_throws_workspace_not_found_exception_for_unregistered_workspace(): void
    {
        $this->expectException(WorkspaceNotFoundException::class);
        Workspace::setDefault('non-existent-workspace');
    }

    public function test_get_required_default_throws_exception_when_none_configured(): void
    {
        $this->assertNull(Workspace::getDefault());

        $this->expectException(DefaultWorkspaceNotConfiguredException::class);
        Workspace::getRequiredDefault();
    }

    public function test_remove_throws_workspace_not_found_exception_for_unregistered_workspace(): void
    {
        $this->expectException(WorkspaceNotFoundException::class);
        Workspace::remove('non-existent-workspace');
    }

    public function test_find_package_path_resolves_nested_two_level_package(): void
    {
        Workspace::add('packages');
        $this->createDummyPackage('packages/acme/my-pkg', 'acme/my-pkg');

        Workspace::sync();

        $resolved = Workspace::findPackagePath('acme/my-pkg');
        $this->assertSame('packages/acme/my-pkg', $resolved);
    }

    public function test_find_package_path_resolves_flat_one_level_package_in_fixed_vendor_workspace(): void
    {
        Workspace::add('labs', 'alex-kassel-labs');
        $this->createDummyPackage('labs/billing-module', 'alex-kassel-labs/billing-module');

        Workspace::sync();

        $resolved = Workspace::findPackagePath('alex-kassel-labs/billing-module');
        $this->assertSame('labs/billing-module', $resolved);
    }

    public function test_resolve_canonical_package_name_preserves_full_names(): void
    {
        $this->assertSame('acme/my-package', Workspace::resolveCanonicalPackageName('acme/my-package'));
        $this->assertSame('vendor/pkg_with_underscore', Workspace::resolveCanonicalPackageName('vendor/pkg_with_underscore'));
        $this->assertSame('vendor/pkg.with.dot', Workspace::resolveCanonicalPackageName('vendor/pkg.with.dot'));
    }

    public function test_resolve_canonical_package_name_resolves_short_name_when_fixed_vendor_workspace_is_default(): void
    {
        Workspace::add('labs', 'alex-kassel-labs', true);

        $resolved = Workspace::resolveCanonicalPackageName('super-agent');
        $this->assertSame('alex-kassel-labs/super-agent', $resolved);
    }

    public function test_resolve_canonical_package_name_returns_raw_name_if_no_fixed_vendor_workspace_configured(): void
    {
        Workspace::add('packages', null, true);

        $resolved = Workspace::resolveCanonicalPackageName('short-name');
        $this->assertSame('short-name', $resolved);
    }

    public function test_add_rejects_parent_directory_traversal(): void
    {
        $this->expectException(InvalidWorkspacePathException::class);
        Workspace::add('../external-dir');
    }

    public function test_add_rejects_absolute_paths(): void
    {
        $this->expectException(InvalidWorkspacePathException::class);
        Workspace::add('/var/workspaces');
    }

    public function test_add_rejects_windows_drive_letter_paths(): void
    {
        $this->expectException(InvalidWorkspacePathException::class);
        Workspace::add('C:/dangerous');
    }

    public function test_add_rejects_invalid_path_segments(): void
    {
        $this->expectException(InvalidWorkspacePathException::class);
        Workspace::add('packages/evil;rm');
    }

    public function test_scan_packages_strictly_obeys_workspace_paradigm(): void
    {
        // Fixed-vendor (flat): should only find 1-level packages, NOT 2-level
        Workspace::add('labs', 'alex-kassel-labs');
        $this->createDummyPackage('labs/correct-flat-pkg', 'alex-kassel-labs/correct-flat-pkg');
        $this->createDummyPackage('labs/wrong/nested-pkg', 'alex-kassel-labs/nested-pkg');

        $packages = Workspace::scanPackages('labs', 'alex-kassel-labs');
        $this->assertContains('correct-flat-pkg', $packages);
        $this->assertNotContains('nested-pkg', $packages);
    }

    public function test_load_uses_in_memory_cache_and_clear_cache_invalidates_it(): void
    {
        Workspace::add('packages');
        $initial = Workspace::load();

        // Mutate workspace.json directly on disk behind the manager's back
        $raw = json_decode(File::get(base_path('workspace.json')), true);
        $raw['default'] = 'manipulated-on-disk';
        File::put(base_path('workspace.json'), json_encode($raw));

        // load() should return cached data, ignoring disk changes until cache is cleared
        $cached = Workspace::load();
        $this->assertSame($initial['default'], $cached['default']);
        $this->assertNotSame('manipulated-on-disk', $cached['default']);

        // clearCache() should force reload from disk
        Workspace::clearCache();
        $reloaded = Workspace::load();
        $this->assertSame('manipulated-on-disk', $reloaded['default']);
    }

    public function test_repository_template_defaults_and_resolves_package_clone_url(): void
    {
        $this->assertSame('git@github.com:{package}.git', Workspace::getRepositoryTemplate());
        $this->assertSame('git@github.com:alex-kassel/test-pkg.git', Workspace::resolvePackageCloneUrl('alex-kassel/test-pkg'));

        Workspace::setRepositoryTemplate('https://gitlab.com/{package}.git');
        $this->assertSame('https://gitlab.com/{package}.git', Workspace::getRepositoryTemplate());
        $this->assertSame('https://gitlab.com/alex-kassel/test-pkg.git', Workspace::resolvePackageCloneUrl('alex-kassel/test-pkg'));
    }

    public function test_ensure_workspace_script_and_composer_hooks(): void
    {
        Workspace::ensureWorkspaceScript();
        $this->assertFileExists(base_path('workspace'));

        Workspace::ensureComposerHooks();
        $composer = json_decode(File::get(base_path('composer.json')), true);

        $this->assertArrayHasKey('pre-install-cmd', $composer['scripts'] ?? []);
        $this->assertArrayHasKey('pre-update-cmd', $composer['scripts'] ?? []);
        $this->assertContains('php workspace restore', $composer['scripts']['pre-install-cmd']);
        $this->assertContains('php workspace restore', $composer['scripts']['pre-update-cmd']);
    }

    public function test_alias_package_in_flat_workspace_renames_dir_and_updates_manifest(): void
    {
        Workspace::add('app/Cores', 'alex-kassel');
        $this->createDummyPackage('app/Cores/scraper-core', 'alex-kassel/scraper-core');
        Workspace::sync();

        $result = Workspace::aliasPackage('scraper-core', 'Scraper');

        $this->assertSame('app/Cores/scraper-core', $result['old_path']);
        $this->assertSame('app/Cores/Scraper', $result['new_path']);
        $this->assertSame('alex-kassel/scraper-core', $result['canonical_name']);

        $this->assertDirectoryDoesNotExist(base_path('app/Cores/scraper-core'));
        $this->assertDirectoryExists(base_path('app/Cores/Scraper'));

        $manifest = Workspace::load();
        $packages = $manifest['workspaces']['app/Cores']['packages'];
        $this->assertSame([['name' => 'scraper-core', 'alias' => 'Scraper']], $packages);

        // Can find by alias
        $this->assertSame('app/Cores/Scraper', Workspace::findPackagePath('Scraper'));
        // Can find by short name
        $this->assertSame('app/Cores/Scraper', Workspace::findPackagePath('scraper-core'));
        // Can find by canonical name
        $this->assertSame('app/Cores/Scraper', Workspace::findPackagePath('alex-kassel/scraper-core'));
    }

    public function test_alias_package_rejects_nested_workspace(): void
    {
        Workspace::add('packages', null, true);
        $this->createDummyPackage('packages/alex-kassel/core-lib', 'alex-kassel/core-lib');
        Workspace::sync();

        $this->expectException(WorkspaceException::class);
        $this->expectExceptionMessage('Aliases are only supported in flat (fixed-vendor) workspaces');
        Workspace::aliasPackage('core-lib', 'CoreLib');
    }

    public function test_find_package_path_throws_ambiguous_package_exception_when_duplicate_names_exist(): void
    {
        Workspace::add('app/Cores', 'alex-kassel');
        Workspace::add('packages', null);
        $this->createDummyPackage('app/Cores/Scraper', 'alex-kassel/scraper');
        $this->createDummyPackage('packages/other/scraper', 'other/scraper');
        Workspace::sync();

        $this->expectException(AmbiguousPackageException::class);
        $this->expectExceptionMessage('Ambiguous package reference [scraper]');
        Workspace::findPackagePath('scraper');
    }

    public function test_find_duplicate_aliases_detects_collisions_across_workspaces(): void
    {
        Workspace::add('app/Cores', 'alex-kassel');
        Workspace::add('packages', null);
        $this->createDummyPackage('app/Cores/Scraper', 'alex-kassel/scraper');
        $this->createDummyPackage('packages/other/Scraper', 'other/scraper');
        Workspace::sync();

        $duplicates = Workspace::findDuplicateAliases('Scraper', 'app/Cores/Scraper');
        $this->assertCount(1, $duplicates);
        $this->assertStringContainsString('packages/other/Scraper', $duplicates[0]);
    }

    public function test_update_composer_path_references_updates_lock_and_installed_json(): void
    {
        // 1. Seed composer.lock with a path repository package
        $lockData = [
            'packages' => [
                [
                    'name' => 'alex-kassel/scraper-core',
                    'dist' => [
                        'type' => 'path',
                        'url' => 'app/Cores/old-dir',
                    ],
                ],
                [
                    'name' => 'other/package',
                    'dist' => [
                        'type' => 'git',
                        'url' => 'https://github.com/other/package.git',
                    ],
                ],
            ],
            'packages-dev' => [
                [
                    'name' => 'alex-kassel/dev-tool',
                    'dist' => [
                        'type' => 'path',
                        'url' => 'packages/dev-tool',
                    ],
                ],
            ],
        ];
        File::put(base_path('composer.lock'), json_encode($lockData, JSON_PRETTY_PRINT));

        // 2. Seed vendor/composer/installed.json
        File::ensureDirectoryExists(base_path('vendor/composer'));
        $installedData = [
            'packages' => [
                [
                    'name' => 'alex-kassel/scraper-core',
                    'dist' => [
                        'type' => 'path',
                        'url' => 'app/Cores/old-dir',
                    ],
                ],
            ],
        ];
        File::put(base_path('vendor/composer/installed.json'), json_encode($installedData, JSON_PRETTY_PRINT));

        // 3. Execute updateComposerPathReferences
        Workspace::updateComposerPathReferences('alex-kassel/scraper-core', 'app/Cores/old-dir', 'app/Cores/new-alias');

        // 4. Assert composer.lock updated
        $updatedLock = json_decode(File::get(base_path('composer.lock')), true);
        $this->assertSame('app/Cores/new-alias', $updatedLock['packages'][0]['dist']['url']);
        $this->assertSame('https://github.com/other/package.git', $updatedLock['packages'][1]['dist']['url']);

        // 5. Assert vendor/composer/installed.json updated
        $updatedInstalled = json_decode(File::get(base_path('vendor/composer/installed.json')), true);
        $this->assertSame('app/Cores/new-alias', $updatedInstalled['packages'][0]['dist']['url']);
    }

    public function test_update_composer_path_references_handles_missing_files_gracefully(): void
    {
        // Neither composer.lock nor installed.json exist in this fresh state
        // Method should not throw any exceptions
        Workspace::updateComposerPathReferences('non/existent', 'old/path', 'new/path');
        $this->assertTrue(true);
    }

    public function test_delete_directory_recursively_does_not_delete_target_of_symlink(): void
    {
        // Create outside sensitive directory and file
        $outsideDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wdt_outside_'.uniqid();
        File::ensureDirectoryExists($outsideDir);
        $sensitiveFile = $outsideDir.DIRECTORY_SEPARATOR.'important.txt';
        File::put($sensitiveFile, 'do not delete');

        // Create package directory with symlink pointing to sensitive file
        $packageDir = base_path('packages/test-pkg');
        File::ensureDirectoryExists($packageDir);
        $symlinkPath = $packageDir.DIRECTORY_SEPARATOR.'symlinked-file.txt';

        if (PHP_OS_FAMILY !== 'Windows') {
            @symlink($sensitiveFile, $symlinkPath);
        } else {
            // Windows: create directory junction or symlink if permitted
            @symlink($sensitiveFile, $symlinkPath);
        }

        if (is_link($symlinkPath)) {
            Workspace::deleteDirectoryRecursively($packageDir);

            // Package dir must be gone, but sensitive outside file MUST still exist!
            $this->assertDirectoryDoesNotExist($packageDir);
            $this->assertFileExists($sensitiveFile);
            $this->assertSame('do not delete', File::get($sensitiveFile));
        } else {
            // If OS environment cannot create file symlinks without elevation, pass gracefully
            $this->assertTrue(true);
        }

        File::deleteDirectory($outsideDir);
    }

    public function test_workspace_sync_preserves_offline_packages_and_custom_metadata(): void
    {
        Workspace::add('packages', null, true);

        // Pre-record an offline package with a custom URL in workspace.json
        Workspace::recordPackage('packages', 'acme/remote-offline-pkg', null, 'git@gitlab.com:acme/remote.git');

        $manifest = Workspace::load();
        $this->assertSame([['name' => 'acme/remote-offline-pkg', 'url' => 'git@gitlab.com:acme/remote.git']], $manifest['workspaces']['packages']['packages']);

        // Run sync() with empty disk — the offline package must NOT be wiped
        Workspace::sync();

        $synced = Workspace::load();
        $this->assertSame([['name' => 'acme/remote-offline-pkg', 'url' => 'git@gitlab.com:acme/remote.git']], $synced['workspaces']['packages']['packages']);
    }

    public function test_composer_manager_rejects_malformed_json(): void
    {
        File::put(base_path('composer.json'), '{ broken json ...');

        $this->expectException(InvalidJsonException::class);
        $composer = app(ComposerManager::class);
        $composer->syncRepositories([]);
    }

    public function test_composer_manager_preserves_keyed_repositories_shape(): void
    {
        $customComposer = [
            'name' => 'test/app',
            'repositories' => [
                'packagist.org' => false,
                'custom-repo' => [
                    'type' => 'vcs',
                    'url' => 'https://github.com/custom/repo.git',
                ],
            ],
        ];

        File::put(base_path('composer.json'), json_encode($customComposer, JSON_PRETTY_PRINT));

        $composer = app(ComposerManager::class);
        $composer->syncRepositories(['packages' => ['vendor' => null, 'packages' => []]]);

        $saved = json_decode(File::get(base_path('composer.json')), true);
        $this->assertArrayHasKey('packagist.org', $saved['repositories']);
        $this->assertFalse($saved['repositories']['packagist.org']);
        $this->assertArrayHasKey('custom-repo', $saved['repositories']);
        $this->assertArrayHasKey('workspace-packages', $saved['repositories']);
    }
}
