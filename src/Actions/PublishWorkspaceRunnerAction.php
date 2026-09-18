<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Actions;

use AlexKassel\StubEngine\StubEngine;
use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\ActionStep;
use AlexKassel\WorkspaceManifest\WorkspaceManifest;
use Generator;
use Illuminate\Support\Facades\File;

class PublishWorkspaceRunnerAction extends BaseAction
{
    public const DEFAULT_RUNNER_NAME = 'workspace';

    public const DEFAULT_STUBS_DIR = 'stubs';

    public const DEFAULT_STUB_FILENAME = 'workspace.stub';

    public const TOKEN_MANIFEST_PATH = '{{ manifestPath }}';

    public const TOKEN_RUNNER_NAME = '{{ runnerName }}';

    public function __construct(
        protected readonly ?StubEngine $stubEngine = null,
    ) {}

    /**
     * @return Generator<int, ActionStep>
     */
    public function execute(
        string $rootPath,
        bool $force = false,
        string $runnerName = self::DEFAULT_RUNNER_NAME,
        ?string $manifestFilename = null,
    ): Generator {
        $configPath = config('workspace-manifest.path');
        $manifestFilename ??= (is_string($configPath) && trim($configPath) !== ''
            ? trim($configPath)
            : WorkspaceManifest::DEFAULT_FILENAME);

        $sourceStub = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'stubs'.DIRECTORY_SEPARATOR.self::DEFAULT_STUB_FILENAME;
        $targetRunner = $rootPath.DIRECTORY_SEPARATOR.$runnerName;
        $overrideStub = $rootPath.DIRECTORY_SEPARATOR.self::DEFAULT_STUBS_DIR.DIRECTORY_SEPARATOR.self::DEFAULT_STUB_FILENAME;

        if (! File::exists($sourceStub)) {
            yield ActionStep::failed("workspace.stub not found at [{$sourceStub}].");

            return;
        }

        $engine = $this->stubEngine ?? app(StubEngine::class);

        $result = $engine->from($sourceStub)
            ->to($targetRunner)
            ->withTokens([
                self::TOKEN_MANIFEST_PATH => $manifestFilename,
                self::TOKEN_RUNNER_NAME => $runnerName,
            ])
            ->override($overrideStub)
            ->force($force)
            ->scaffold();

        if ($result->successful()) {
            @chmod($targetRunner, 0755);

            yield ActionStep::created("Published standalone workspace runner to [./{$runnerName}].");
        } else {
            yield ActionStep::skipped("Standalone workspace runner [./{$runnerName}] already exists.");
        }
    }
}
