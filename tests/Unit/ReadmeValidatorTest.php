<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Unit;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ReadmeValidator;
use AlexKassel\WorkspaceDevelopmentToolkit\Tests\TestCase;
use Illuminate\Support\Facades\File;

class ReadmeValidatorTest extends TestCase
{
    protected ReadmeValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = app(ReadmeValidator::class);
    }

    public function test_validate_fails_if_readme_is_missing(): void
    {
        Workspace::add('packages', null, true);
        $dir = $this->tempDir.'/packages/acme/no-readme';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/composer.json', json_encode(['name' => 'acme/no-readme']));

        $result = $this->validator->validate('acme/no-readme');

        $this->assertSame('failed', $result['status']);
        $this->assertSame('failed', $result['checks']['file_exists']['status']);
    }

    public function test_validate_fails_if_header_is_missing(): void
    {
        Workspace::add('packages', null, true);
        $dir = $this->tempDir.'/packages/acme/bad-header';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/composer.json', json_encode(['name' => 'acme/bad-header']));
        File::put($dir.'/README.md', 'Just some text without a title heading.');

        $result = $this->validator->validate('acme/bad-header');

        $this->assertSame('failed', $result['status']);
        $this->assertSame('failed', $result['checks']['hero_header']['status']);
    }

    public function test_validate_fails_if_required_sections_are_missing(): void
    {
        Workspace::add('packages', null, true);
        $dir = $this->tempDir.'/packages/acme/missing-sections';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/composer.json', json_encode(['name' => 'acme/missing-sections']));
        File::put($dir.'/README.md', "# My Package\n\n## Requirements\n\n## Installation\n");

        $result = $this->validator->validate('acme/missing-sections');

        $this->assertSame('failed', $result['status']);
        $this->assertSame('failed', $result['checks']['required_sections']['status']);
        $this->assertStringContainsString('usage', strtolower($result['checks']['required_sections']['message']));
    }

    public function test_validate_fails_if_placeholders_exist(): void
    {
        Workspace::add('packages', null, true);
        $dir = $this->tempDir.'/packages/acme/placeholders';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/composer.json', json_encode(['name' => 'acme/placeholders']));
        File::put($dir.'/README.md', "# My Package\n\ncomposer require <vendor>/<package>\n\n## Requirements\n\n## Installation\n\n## Usage\n\n## Testing\n\n## License\n");

        $result = $this->validator->validate('acme/placeholders');

        $this->assertSame('failed', $result['status']);
        $this->assertSame('failed', $result['checks']['placeholders']['status']);
    }

    public function test_validate_passes_for_clean_readme(): void
    {
        Workspace::add('packages', null, true);
        $dir = $this->tempDir.'/packages/acme/valid-pkg';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/composer.json', json_encode(['name' => 'acme/valid-pkg']));
        File::put($dir.'/README.md', "# Valid Pkg\n\n## Requirements\n\n## Installation\n\n## Usage\n\n## Testing\n\n## License\n");

        $result = $this->validator->validate('acme/valid-pkg');

        $this->assertSame('passed', $result['status']);
        $this->assertSame(0, $result['summary']['failed']);
    }
}
