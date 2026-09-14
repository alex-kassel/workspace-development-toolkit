<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Checks;

use AlexKassel\WorkspaceDevelopmentToolkit\Contracts\PackageCheckInterface;
use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\CheckResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

abstract class BaseCheck implements PackageCheckInterface
{
    /**
     * Determine if this check applies to the specified package path.
     *
     * @param  array<string, mixed>  $options
     */
    public function isApplicable(string $packagePath, ?string $packageName = null, array $options = []): bool
    {
        return true;
    }

    /**
     * Resolve package name from composer.json or directory structure.
     */
    protected function resolvePackageName(string $packagePath): string
    {
        $composerPath = rtrim($packagePath, '/\\').DIRECTORY_SEPARATOR.'composer.json';

        if (File::exists($composerPath)) {
            try {
                $data = json_decode(File::get($composerPath), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($data) && ! empty($data['name'])) {
                    return (string) $data['name'];
                }
            } catch (\Throwable) {
                // Fallback to directory name parsing
            }
        }

        $normalized = str_replace('\\', '/', trim($packagePath, '/\\'));
        $parts = explode('/', $normalized);

        if (count($parts) >= 2) {
            return $parts[count($parts) - 2].'/'.$parts[count($parts) - 1];
        }

        return basename($normalized);
    }

    /**
     * Normalize directory path.
     */
    protected function normalizePath(string $path): string
    {
        if (File::exists($path)) {
            return realpath($path) ?: $path;
        }

        $abs = base_path($path);
        if (File::exists($abs)) {
            return realpath($abs) ?: $abs;
        }

        return $path;
    }

    /**
     * Helper to execute a command process.
     *
     * @param  array<int, string>  $command
     * @param  array<string, string>  $env
     */
    protected function runCommand(
        array $command,
        string $cwd,
        string $check,
        string $packageName,
        array $env = [],
        int $timeout = 120
    ): CheckResult {
        $startTime = microtime(true);
        $pendingProcess = Process::path($cwd)->timeout($timeout);

        if (! empty($env)) {
            $pendingProcess = $pendingProcess->env($env);
        }

        try {
            $result = $pendingProcess->run($command);
            $exitCode = $result->exitCode() ?? 1;
            $output = trim($result->output()."\n".$result->errorOutput());
        } catch (\Throwable) {
            $exitCode = 1;
            $output = 'Process execution failed.';
        }

        $duration = round(microtime(true) - $startTime, 3);
        $status = ($exitCode === 0) ? 'passed' : 'failed';

        return new CheckResult(
            check: $check,
            package: $packageName,
            status: $status,
            output: $output,
            durationSeconds: $duration,
        );
    }
}
