<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Enums\CheckStatus;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
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
        {package : Package name in vendor/package format (e.g. acme/my-pkg) or relative package path}
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
        $rawPackage = (string) $this->argument('package');
        $isJson = (bool) $this->option('json');

        try {
            $result = $this->validator->validate($rawPackage);
        } catch (WorkspaceException $e) {
            if ($isJson) {
                $this->line(json_encode([
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

                return self::FAILURE;
            }

            return $this->handleWorkspaceException($e);
        } catch (RuntimeException $e) {
            if ($isJson) {
                $this->line(json_encode([
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error('Error: '.$e->getMessage());
                $this->line('  <comment>How to fix:</comment> Check registered packages using:');
                $this->line('  <info>php artisan workspace:list</info>');
            }

            return self::FAILURE;
        }

        if ($isJson) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $result->isPassed() ? self::SUCCESS : self::FAILURE;
        }

        $this->info("README Verification: {$result->package} ({$result->path})");
        $this->newLine();

        foreach ($result->checks as $check) {
            $statusLabel = CheckStatus::format($check['status'], bracketed: true);

            $this->line("  {$statusLabel} <comment>{$check['name']}</comment>: {$check['message']}");
        }

        $this->newLine();

        if ($result->isPassed()) {
            $this->info('README verification passed. All checks passed.');

            return self::SUCCESS;
        }

        $this->error("README verification failed with {$result->failedCount()} issue(s).");

        return self::FAILURE;
    }
}
