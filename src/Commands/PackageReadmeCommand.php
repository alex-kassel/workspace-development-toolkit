<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerManager;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ReadmeValidator;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceManager;
use RuntimeException;

class PackageReadmeCommand extends BasePackageCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:readme
        {name : Package name in vendor/package format (e.g. acme/my-pkg) or relative package path}
        {--json : Output machine-readable JSON summary}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Validate package README.md compliance with configured standard';

    public function __construct(
        WorkspaceManager $workspace,
        ComposerManager $composer,
        protected readonly ReadmeValidator $validator,
    ) {
        parent::__construct($workspace, $composer);
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $rawPackage = (string) ($this->hasArgument('package') ? $this->argument('package') : $this->argument('name'));
        $isJson = (bool) $this->option('json');

        try {
            $result = $this->validator->validate($rawPackage);
        } catch (RuntimeException $e) {
            if ($isJson) {
                $this->line(json_encode([
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error('Error: '.$e->getMessage());
            }

            return self::FAILURE;
        }

        if ($isJson) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $result['status'] === 'passed' ? self::SUCCESS : self::FAILURE;
        }

        $this->info("README Verification: {$result['package']} ({$result['path']})");
        $this->newLine();

        foreach ($result['checks'] as $check) {
            $statusLabel = ($check['status'] === 'passed')
                ? '<info>[PASS]</info>'
                : '<error>[FAIL]</error>';

            $this->line("  {$statusLabel} <comment>{$check['name']}</comment>: {$check['message']}");
        }

        $this->newLine();

        if ($result['status'] === 'passed') {
            $this->info('README verification passed. All checks passed.');

            return self::SUCCESS;
        }

        $this->error("README verification failed with {$result['summary']['failed']} issue(s).");

        return self::FAILURE;
    }
}
