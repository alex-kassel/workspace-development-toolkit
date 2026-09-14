<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use Illuminate\Support\Facades\File;

class BinaryResolver
{
    /**
     * Resolve binary path from vendor/bin, including Windows extensions.
     */
    public function resolve(string $binaryName): ?string
    {
        $vendorBin = base_path('vendor/bin');

        if (PHP_OS_FAMILY === 'Windows') {
            foreach (['.bat', '.exe', '.cmd'] as $ext) {
                $candidate = $vendorBin.DIRECTORY_SEPARATOR.$binaryName.$ext;
                if (File::exists($candidate)) {
                    return $candidate;
                }
            }
        }

        $binary = $vendorBin.DIRECTORY_SEPARATOR.$binaryName;
        if (File::exists($binary)) {
            return $binary;
        }

        return null;
    }
}
