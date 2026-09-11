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
}
