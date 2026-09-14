<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Unit;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\AmbiguousPackageException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\ComposerProcessException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\DefaultWorkspaceNotConfiguredException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\InvalidJsonException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\InvalidWorkspacePathException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\PackageNotFoundException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceNotFoundException;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;

class ActionableExceptionsTest extends TestCase
{
    public function test_workspace_not_found_exception_code_and_steps(): void
    {
        $e = new WorkspaceNotFoundException('clients/beta', ['packages', 'labs']);

        $this->assertSame('WS_WORKSPACE_NOT_FOUND', $e->errorCode());
        $this->assertNotEmpty($e->remediationSteps());
        $this->assertNotNull($e->agentInstructions());
        $this->assertStringContainsString('[WS_WORKSPACE_NOT_FOUND]', $e->getFormattedMessage());
    }

    public function test_package_not_found_exception_code_and_steps(): void
    {
        $e = new PackageNotFoundException('acme/unknown-pkg', ['packages']);

        $this->assertSame('WS_PACKAGE_NOT_FOUND', $e->errorCode());
        $this->assertNotEmpty($e->remediationSteps());
        $this->assertNotNull($e->agentInstructions());
    }

    public function test_default_workspace_missing_code(): void
    {
        $e = new DefaultWorkspaceNotConfiguredException;

        $this->assertSame('WS_DEFAULT_WORKSPACE_MISSING', $e->errorCode());
        $this->assertNotEmpty($e->remediationSteps());
    }

    public function test_ambiguous_package_exception_code(): void
    {
        $e = new AmbiguousPackageException('billing', ['packages/billing', 'labs/billing']);

        $this->assertSame('WS_AMBIGUOUS_PACKAGE', $e->errorCode());
        $this->assertNotEmpty($e->remediationSteps());
    }

    public function test_composer_process_exception_code(): void
    {
        $e = new ComposerProcessException('composer require foo/bar', 1, 'Package not found');

        $this->assertSame('WS_COMPOSER_FAILED', $e->errorCode());
        $this->assertNotEmpty($e->remediationSteps());
    }

    public function test_invalid_json_exception_code(): void
    {
        $e = new InvalidJsonException('/path/to/file.json', 'Syntax error');

        $this->assertSame('WS_INVALID_JSON', $e->errorCode());
        $this->assertNotEmpty($e->remediationSteps());
    }

    public function test_invalid_workspace_path_exception_code(): void
    {
        $e = new InvalidWorkspacePathException('../outside', 'escapes root');

        $this->assertSame('WS_INVALID_WORKSPACE_PATH', $e->errorCode());
        $this->assertNotEmpty($e->remediationSteps());
    }

    public function test_base_workspace_exception_remediation_steps_parses_multiline(): void
    {
        $e = new WorkspaceException('Something went wrong', "Step 1\n• Step 2\n- Step 3");

        $steps = $e->remediationSteps();
        $this->assertCount(3, $steps);
        $this->assertSame('Step 1', $steps[0]);
        $this->assertSame('Step 2', $steps[1]);
        $this->assertSame('Step 3', $steps[2]);
    }
}
