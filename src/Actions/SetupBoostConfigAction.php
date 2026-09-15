<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Actions;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\ActionStep;
use Generator;
use Illuminate\Support\Facades\File;

class SetupBoostConfigAction extends BaseAction
{
    /**
     * @param  array<int, string>  $packages
     * @return Generator<int, ActionStep>
     */
    public function execute(
        string $rootPath,
        array $packages = ['alex-kassel/workspace-development-toolkit'],
        bool $force = false,
    ): Generator {
        $boostJsonPath = $rootPath.DIRECTORY_SEPARATOR.'boost.json';
        $bundledStub = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'stubs'.DIRECTORY_SEPARATOR.'boost.json.stub';

        if (! File::exists($boostJsonPath)) {
            if (File::exists($bundledStub)) {
                File::copy($bundledStub, $boostJsonPath);
                yield ActionStep::created('Created boost.json with guidelines protection and package registration.');

                return;
            }

            $defaultConfig = [
                'packages' => array_values($packages),
                'guidelines' => false,
            ];
            File::put($boostJsonPath, json_encode($defaultConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            yield ActionStep::created('Created boost.json with guidelines protection.');

            return;
        }

        $content = json_decode((string) File::get($boostJsonPath), true);
        if (! is_array($content)) {
            yield ActionStep::failed("Unable to parse [{$boostJsonPath}].");

            return;
        }

        $modified = false;
        if (($content['guidelines'] ?? true) !== false) {
            $content['guidelines'] = false;
            $modified = true;
        }

        $existingPackages = (array) ($content['packages'] ?? []);
        foreach ($packages as $pkg) {
            if (! in_array($pkg, $existingPackages, true)) {
                $existingPackages[] = $pkg;
                $modified = true;
            }
        }
        $content['packages'] = array_values($existingPackages);

        if ($modified || $force) {
            File::put($boostJsonPath, json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            yield ActionStep::updated('Configured existing boost.json to protect guidelines and register toolkit.');

            return;
        }

        yield ActionStep::skipped('boost.json is already configured.');
    }
}
