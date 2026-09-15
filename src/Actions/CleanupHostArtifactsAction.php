<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Actions;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\ActionStep;
use Generator;
use Illuminate\Support\Facades\File;

class CleanupHostArtifactsAction extends BaseAction
{
    /**
     * @param  array<int, string>  $artifacts
     * @return Generator<int, ActionStep>
     */
    public function execute(string $rootPath, array $artifacts): Generator
    {
        $cleanedCount = 0;
        foreach ($artifacts as $file) {
            $target = $rootPath.DIRECTORY_SEPARATOR.trim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, (string) $file), DIRECTORY_SEPARATOR);

            if (File::exists($target)) {
                File::delete($target);
                $cleanedCount++;
                yield ActionStep::cleaned("Removed redundant host artifact [{$file}].");
            }
        }

        if ($cleanedCount === 0) {
            yield ActionStep::skipped('No redundant host artifacts found.');
        }
    }
}
