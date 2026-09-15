<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Actions;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\ActionStep;
use Generator;
use Illuminate\Support\Facades\File;
use Throwable;

class RunCustomHookAction extends BaseAction
{
    /**
     * @param  array<int, string>  $candidatePaths
     * @param  array<string, mixed>  $contextVariables
     * @return Generator<int, ActionStep>
     */
    public function execute(string $rootPath, array $candidatePaths = [], array $contextVariables = []): Generator
    {
        $defaultCandidates = [
            $rootPath.DIRECTORY_SEPARATOR.'stubs'.DIRECTORY_SEPARATOR.'workspace'.DIRECTORY_SEPARATOR.'hooks'.DIRECTORY_SEPARATOR.'post-install.php',
            $rootPath.DIRECTORY_SEPARATOR.'.workspace-post-install.php',
        ];

        $allCandidates = array_values(array_filter([...$candidatePaths, ...$defaultCandidates]));

        foreach ($allCandidates as $candidate) {
            if (File::exists($candidate)) {
                $rel = trim(str_replace($rootPath, '', $candidate), DIRECTORY_SEPARATOR);

                try {
                    (static function (array $vars, string $file): void {
                        extract($vars, EXTR_SKIP);
                        require $file;
                    })($contextVariables, $candidate);

                    yield ActionStep::executed("Executed custom post-install hook from [{$rel}].");
                } catch (Throwable $e) {
                    yield ActionStep::failed("Post-install hook [{$rel}] failed: {$e->getMessage()}");
                }

                return;
            }
        }

        yield ActionStep::skipped('No custom post-install hook found.');
    }
}
