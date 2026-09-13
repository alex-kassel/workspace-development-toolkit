<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\ReadmeValidationResult;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use RuntimeException;

class ReadmeValidator
{
    public function __construct(
        protected PackageResolver $packageResolver,
    ) {}

    /**
     * Validate package README.md compliance.
     */
    public function validate(string $packageNameOrPath): ReadmeValidationResult
    {
        if (File::isDirectory($packageNameOrPath)) {
            $fullPath = realpath($packageNameOrPath) ?: $packageNameOrPath;
            $packagePath = $packageNameOrPath;
        } else {
            $packagePath = $this->packageResolver->findPackagePath($packageNameOrPath);
            if ($packagePath === null) {
                $normalized = trim(str_replace('\\', '/', $packageNameOrPath), '/');
                if (File::isDirectory(base_path($normalized))) {
                    $packagePath = $normalized;
                    $fullPath = base_path($packagePath);
                } else {
                    throw new RuntimeException("Package [{$packageNameOrPath}] not found in any registered workspace.");
                }
            } else {
                $fullPath = base_path($packagePath);
            }
        }

        $packageName = $this->packageResolver->resolveCanonicalPackageName($packageNameOrPath);
        if ($packageName === $packageNameOrPath) {
            $composerJsonPath = $fullPath.DIRECTORY_SEPARATOR.'composer.json';
            if (File::exists($composerJsonPath)) {
                $manifest = json_decode(File::get($composerJsonPath), true) ?: [];
                if (! empty($manifest['name'])) {
                    $packageName = (string) $manifest['name'];
                }
            }
        }

        $readmePath = $fullPath.DIRECTORY_SEPARATOR.'README.md';
        $checks = [];

        // 1. File existence
        if (! File::exists($readmePath)) {
            $checks['file_exists'] = [
                'name' => 'README.md File Existence',
                'status' => 'failed',
                'message' => 'README.md not found in package directory.',
            ];
        } else {
            $checks['file_exists'] = [
                'name' => 'README.md File Existence',
                'status' => 'passed',
                'message' => 'README.md found.',
            ];

            $content = File::get($readmePath);

            // 2. Header (# Title or <h1 align="center">)
            $hasMarkdownHeader = preg_match('/^#\s+[^\r\n]+/m', $content) === 1;
            $hasHtmlHeader = preg_match('/<h1\s+align=["\']center["\']>/i', $content) === 1;

            if ($hasMarkdownHeader || $hasHtmlHeader) {
                $checks['hero_header'] = [
                    'name' => 'Hero Header (title)',
                    'status' => 'passed',
                    'message' => 'README contains a valid title heading.',
                ];
            } else {
                $checks['hero_header'] = [
                    'name' => 'Hero Header (title)',
                    'status' => 'failed',
                    'message' => 'Missing title header (requires # Heading or <h1 align="center">).',
                ];
            }

            // 3. Configured required sections
            $requiredSections = Config::get('workspace.readme.required_sections', [
                'Requirements',
                'Installation',
                'Usage',
                'Testing',
                'License',
            ]);

            $missingSections = [];
            foreach ($requiredSections as $section) {
                $escapedSection = preg_quote($section, '/');
                if (preg_match('/^#{1,6}\s+'.$escapedSection.'/im', $content) !== 1) {
                    $missingSections[] = $section;
                }
            }

            if (empty($missingSections)) {
                $checks['required_sections'] = [
                    'name' => 'Required Sections',
                    'status' => 'passed',
                    'message' => 'All required sections are present.',
                ];
            } else {
                $checks['required_sections'] = [
                    'name' => 'Required Sections',
                    'status' => 'failed',
                    'message' => 'Missing required section(s): '.implode(', ', $missingSections),
                ];
            }

            // 4. Unfilled Placeholders
            if (preg_match('/<vendor>\/<package>/i', $content) === 1 || preg_match('/Vendor\\\\Package/i', $content) === 1) {
                $checks['placeholders'] = [
                    'name' => 'Unfilled Placeholders',
                    'status' => 'failed',
                    'message' => 'Found unfilled placeholders (<vendor>/<package> or Vendor\\Package).',
                ];
            } else {
                $checks['placeholders'] = [
                    'name' => 'Unfilled Placeholders',
                    'status' => 'passed',
                    'message' => 'No unfilled template placeholders detected.',
                ];
            }

            // 5. Badge syntax (no double pipes outside code blocks)
            $contentNoCode = preg_replace('/```[\\s\\S]*?```/', '', $content) ?? $content;
            $contentNoCode = preg_replace('/`[^`\r\n]*`/', '', $contentNoCode) ?? $contentNoCode;
            if (str_contains($contentNoCode, '||')) {
                $checks['badge_syntax'] = [
                    'name' => 'Badge & Prose Syntax',
                    'status' => 'failed',
                    'message' => 'Found raw double pipes "||" outside code blocks.',
                ];
            } else {
                $checks['badge_syntax'] = [
                    'name' => 'Badge & Prose Syntax',
                    'status' => 'passed',
                    'message' => 'Clean badge and prose syntax.',
                ];
            }
        }

        $passedCount = 0;
        $failedCount = 0;
        foreach ($checks as $check) {
            if ($check['status'] === 'passed') {
                $passedCount++;
            } else {
                $failedCount++;
            }
        }

        return new ReadmeValidationResult(
            package: $packageName,
            path: $packagePath,
            status: ($failedCount === 0) ? 'passed' : 'failed',
            summary: [
                'passed' => $passedCount,
                'failed' => $failedCount,
            ],
            checks: $checks,
        );
    }
}
