<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Tests\Support\Chaos;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Assert;

final class FilesystemSnapshot
{
    /**
     * @var array<string, string> Keyed by absolute file path, value is sha256 hash
     */
    private array $hashes = [];

    /**
     * @param  list<string>  $protectedPaths  List of directory or file paths to protect
     */
    public function __construct(
        private readonly array $protectedPaths
    ) {
        $this->capture();
    }

    /**
     * Capture SHA256 hashes of all protected files.
     */
    public function capture(): void
    {
        $this->hashes = [];

        foreach ($this->protectedPaths as $path) {
            if (File::isFile($path)) {
                $this->hashes[$path] = hash_file('sha256', $path) ?: '';
            } elseif (File::isDirectory($path)) {
                $allFiles = File::allFiles($path, true);
                foreach ($allFiles as $file) {
                    $real = $file->getRealPath();
                    if ($real !== false) {
                        $this->hashes[$real] = hash_file('sha256', $real) ?: '';
                    }
                }
            }
        }
    }

    /**
     * Verify that none of the protected files have been deleted, altered, or augmented.
     */
    public function verifyUntouched(): void
    {
        // 1. Verify every captured file still exists with the exact same hash
        foreach ($this->hashes as $filePath => $originalHash) {
            Assert::assertFileExists(
                $filePath,
                "Security violation: Protected file [{$filePath}] was deleted by a destructive command!"
            );

            $currentHash = hash_file('sha256', $filePath);
            Assert::assertSame(
                $originalHash,
                $currentHash,
                "Security violation: Protected file [{$filePath}] was modified by a destructive command!"
            );
        }

        // 2. Verify no new rogue files were dropped into protected directories
        foreach ($this->protectedPaths as $path) {
            if (File::isDirectory($path)) {
                $allFiles = File::allFiles($path, true);
                foreach ($allFiles as $file) {
                    $real = $file->getRealPath();
                    if ($real !== false) {
                        Assert::assertArrayHasKey(
                            $real,
                            $this->hashes,
                            "Security violation: Unexpected new file [{$real}] was created in protected directory [{$path}]!"
                        );
                    }
                }
            }
        }
    }
}
