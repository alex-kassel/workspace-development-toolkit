<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Unit;

use AlexKassel\WorkspaceDevelopmentToolkit\Services\Platform;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;

class PlatformTest extends TestCase
{
    public function test_platform_generates_correct_inspect_command(): void
    {
        $cmd = Platform::inspectDirectoryCommand('packages/acme/manual-folder');

        if (Platform::isWindows()) {
            $this->assertStringStartsWith('dir', $cmd);
            $this->assertStringContainsString('packages\\acme\\manual-folder', $cmd);
        } else {
            $this->assertSame('ls -la "packages/acme/manual-folder"', $cmd);
        }
    }

    public function test_platform_generates_correct_delete_command(): void
    {
        $cmd = Platform::deleteDirectoryCommand('packages/acme/manual-folder');

        if (Platform::isWindows()) {
            $this->assertStringStartsWith('rmdir /s /q', $cmd);
            $this->assertStringContainsString('packages\\acme\\manual-folder', $cmd);
        } else {
            $this->assertSame('rm -rf "packages/acme/manual-folder"', $cmd);
        }
    }
}
