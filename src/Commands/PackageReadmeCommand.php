<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Services\ReadmeValidator;
use Illuminate\Console\Command;
use RuntimeException;

class PackageReadmeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:readme
        {name : The vendor/package name or relative package path}
        {--json : Output machine-readable JSON summary}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Validate package README.md compliance with configured standard';

    /**
     * Execute the console command.
     */
    public function handle(ReadmeValidator $validator): int
    {
        $rawName = (string) $this->argument('name');
        $isJson = (bool) $this->option('json');

        try {
            $result = $validator->validate($rawName);
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
