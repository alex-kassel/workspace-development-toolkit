<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Unit;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\DefaultWorkspaceNotConfiguredException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\InvalidJsonException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceNotFoundException;
use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;

require_once dirname(__DIR__).'/TestCase.php';

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
}
