<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Unit;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\Config;

class WorkspaceConfigTest extends TestCase
{
    public function test_workspace_config_reads_repository_url_template(): void
    {
        $config = require __DIR__.'/../../config/workspace.php';
        $this->assertArrayHasKey('repository_url_template', $config);
        $this->assertSame('git@github.com:{package}.git', $config['repository_url_template']);
    }

    public function test_manifest_repository_resolves_repository_url_template_from_config(): void
    {
        Config::set('workspace.repository_url_template', 'https://custom-git.com/{package}.git');

        $this->assertSame('https://custom-git.com/{package}.git', Workspace::getRepositoryTemplate());
    }

    public function test_workspace_config_parses_comma_separated_trusted_organizations(): void
    {
        putenv('WORKSPACE_TRUSTED_ORGANIZATIONS_COMMASEPARATED=acme, spatie , alex-kassel');

        $config = require __DIR__.'/../../config/workspace.php';
        $this->assertSame(['acme', 'spatie', 'alex-kassel'], $config['trusted_organizations']);

        putenv('WORKSPACE_TRUSTED_ORGANIZATIONS_COMMASEPARATED');
    }
}
