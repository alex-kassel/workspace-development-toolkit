<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\CheckResult;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;

class PackageVerifier
{
    public const DEFAULT_PHPSTAN_LEVEL = 8;

    public function __construct(
        protected ?IsolatedPackageVerifier $isolatedVerifier = null
    ) {}

    /**
     * Run isolated package verification.
     */
    public function checkIsolated(string $packagePath, ?string $packageName = null): CheckResult
    {
        $this->isolatedVerifier ??= app(IsolatedPackageVerifier::class);

        return $this->isolatedVerifier->verify($packagePath, $packageName);
    }

    /**
     * Resolve binary path from vendor/bin, including Windows extensions.
     */
    public function resolveBinary(string $binaryName): ?string
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

    /**
     * Resolve package name from composer.json or directory structure.
     */
    public function resolvePackageName(string $packagePath): string
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
     * Run Composer validation check.
     */
    public function checkComposer(string $packagePath, ?string $packageName = null): CheckResult
    {
        $absPackagePath = $this->normalizePath($packagePath);
        $packageName ??= $this->resolvePackageName($absPackagePath);

        $composerPath = $absPackagePath.DIRECTORY_SEPARATOR.'composer.json';
        if (! File::exists($composerPath)) {
            return new CheckResult(
                check: 'composer',
                package: $packageName,
                status: 'failed',
                output: 'composer.json not found in package directory.',
                durationSeconds: 0.0,
            );
        }

        $relComposer = str_replace('\\', '/', ltrim(str_replace(base_path(), '', $composerPath), '/\\'));

        return $this->runCommand(
            ['composer', 'validate', '--strict', $relComposer],
            base_path(),
            'composer',
            $packageName
        );
    }

    /**
     * Run Pint code style check.
     */
    public function checkPint(string $packagePath, ?string $packageName = null, bool $fix = true): CheckResult
    {
        $absPackagePath = $this->normalizePath($packagePath);
        $packageName ??= $this->resolvePackageName($absPackagePath);

        $pintBin = $this->resolveBinary('pint');
        if ($pintBin === null) {
            return new CheckResult(
                check: 'pint',
                package: $packageName,
                status: 'failed',
                output: 'Pint binary not found in vendor/bin. Run "composer require --dev laravel/pint" on the host.',
                durationSeconds: 0.0,
            );
        }

        $relPath = str_replace('\\', '/', ltrim(str_replace(base_path(), '', $absPackagePath), '/\\'));
        $command = [$pintBin, $relPath];
        if (! $fix) {
            $command[] = '--test';
        }

        return $this->runCommand(
            $command,
            base_path(),
            'pint',
            $packageName
        );
    }

    /**
     * Run PHPStan static analysis check.
     */
    public function checkPhpstan(string $packagePath, ?string $packageName = null): CheckResult
    {
        $absPackagePath = $this->normalizePath($packagePath);
        $packageName ??= $this->resolvePackageName($absPackagePath);

        $phpstanBin = $this->resolveBinary('phpstan');
        if ($phpstanBin === null) {
            return new CheckResult(
                check: 'phpstan',
                package: $packageName,
                status: 'failed',
                output: 'PHPStan binary not found in vendor/bin. Run "composer require --dev phpstan/phpstan" on the host.',
                durationSeconds: 0.0,
            );
        }

        if (! File::isDirectory($absPackagePath.DIRECTORY_SEPARATOR.'src')) {
            return new CheckResult(
                check: 'phpstan',
                package: $packageName,
                status: 'skipped',
                output: 'No src/ directory found in package.',
                durationSeconds: 0.0,
            );
        }

        $relPath = str_replace('\\', '/', ltrim(str_replace(base_path(), '', $absPackagePath), '/\\'));
        $neonConfig = null;
        foreach (['phpstan.neon', 'phpstan.neon.dist'] as $cfg) {
            if (File::exists($absPackagePath.DIRECTORY_SEPARATOR.$cfg)) {
                $neonConfig = $relPath.'/'.$cfg;
                break;
            }
        }

        $command = [$phpstanBin, 'analyse'];
        if ($neonConfig !== null) {
            $command[] = '--configuration='.$neonConfig;
        } else {
            $command[] = $relPath.'/src';
            $command[] = '--level='.self::DEFAULT_PHPSTAN_LEVEL;
        }
        $command[] = '--memory-limit=1G';

        return $this->runCommand(
            $command,
            base_path(),
            'phpstan',
            $packageName
        );
    }

    /**
     * Run automated test suite check (PHPUnit / Pest).
     */
    public function checkTests(string $packagePath, ?string $packageName = null): CheckResult
    {
        $absPackagePath = $this->normalizePath($packagePath);
        $packageName ??= $this->resolvePackageName($absPackagePath);

        $phpunitXml = null;
        foreach (['phpunit.xml', 'phpunit.xml.dist'] as $cfg) {
            if (File::exists($absPackagePath.DIRECTORY_SEPARATOR.$cfg)) {
                $phpunitXml = $cfg;
                break;
            }
        }

        if ($phpunitXml === null) {
            return new CheckResult(
                check: 'tests',
                package: $packageName,
                status: 'skipped',
                output: 'No phpunit.xml or phpunit.xml.dist found in package directory.',
                durationSeconds: 0.0,
            );
        }

        $relPath = str_replace('\\', '/', ltrim(str_replace(base_path(), '', $absPackagePath), '/\\'));
        $xmlRel = $relPath.'/'.$phpunitXml;

        $pestBin = $this->resolveBinary('pest');
        $phpunitBin = $this->resolveBinary('phpunit');
        $isPest = File::exists($absPackagePath.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Pest.php') && $pestBin !== null;

        if ($isPest) {
            $command = [$pestBin, '-c', $xmlRel];
        } elseif ($phpunitBin !== null) {
            $command = [$phpunitBin, '-c', $xmlRel];
        } else {
            $artisan = base_path('artisan');
            if (File::exists($artisan)) {
                $command = [PHP_BINARY, 'artisan', 'test', '-c', $xmlRel];
            } else {
                return new CheckResult(
                    check: 'tests',
                    package: $packageName,
                    status: 'failed',
                    output: 'No test runner (phpunit or pest) found in vendor/bin. Run "composer require --dev phpunit/phpunit" on the host.',
                    durationSeconds: 0.0,
                );
            }
        }

        $command[] = '--fail-on-empty-test-suite';

        $env = [
            'APP_ENV' => 'testing',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
        ];

        return $this->runCommand(
            $command,
            base_path(),
            'tests',
            $packageName,
            $env
        );
    }

    /**
     * Run all selected checks for a package.
     *
     * @param  array<int, string>  $only
     * @return array<int, CheckResult>
     */
    public function checkAll(
        string $packagePath,
        string|array|null $packageName = null,
        string|array $tier = 'deep',
        array $only = [],
        bool $fix = true,
        bool $isolated = false
    ): array {
        if ($packageName === 'quick' || $packageName === 'deep') {
            if (is_array($tier)) {
                $isolated = (bool) $fix;
                $fix = (bool) ($only ?: false);
                $only = $tier;
            }
            $tier = $packageName;
            $packageName = null;
        } elseif (is_array($packageName)) {
            $only = $packageName;
            $packageName = null;
        }

        if (is_array($tier)) {
            $isolated = (bool) $fix;
            $fix = (bool) ($only ?: false);
            $only = $tier;
            $tier = 'deep';
        }

        $absPackagePath = $this->normalizePath($packagePath);
        $packageName ??= $this->resolvePackageName($absPackagePath);

        $allowedChecks = ['composer', 'pint', 'phpstan', 'tests', 'isolated'];
        if (! empty($only)) {
            $only = array_map('trim', $only);
            $invalid = array_diff($only, $allowedChecks);
            if (! empty($invalid)) {
                throw new InvalidArgumentException('Unknown check(s): '.implode(', ', $invalid).'. Allowed checks: '.implode(', ', $allowedChecks));
            }
        }

        $checks = match ($tier) {
            'quick' => ['composer', 'pint'],
            'deep' => ['composer', 'pint', 'phpstan', 'tests'],
            default => ['composer', 'pint', 'phpstan', 'tests'],
        };

        if ($isolated || in_array('isolated', $only, true)) {
            $checks[] = 'isolated';
        }

        if (! empty($only)) {
            $checks = array_values(array_intersect($checks, $only));
        }

        $results = [];

        if (in_array('composer', $checks, true)) {
            $results[] = $this->checkComposer($absPackagePath, $packageName);
        }

        if (in_array('pint', $checks, true)) {
            $results[] = $this->checkPint($absPackagePath, $packageName, $fix);
        }

        if (in_array('phpstan', $checks, true)) {
            $results[] = $this->checkPhpstan($absPackagePath, $packageName);
        }

        if (in_array('tests', $checks, true)) {
            $results[] = $this->checkTests($absPackagePath, $packageName);
        }

        if (in_array('isolated', $checks, true)) {
            $results[] = $this->checkIsolated($absPackagePath, $packageName);
        }

        return $results;
    }

    /**
     * Check multiple packages, utilizing Process::pool() for read-only parallel checks.
     *
     * @param  array<string|int, string>  $packagePaths  Array of package paths, optionally keyed by package name
     * @param  array<int, string>  $only
     * @return array<string, array<int, CheckResult>>
     */
    public function checkAllPackages(
        array $packagePaths,
        string|array $tier = 'deep',
        array $only = [],
        bool $fix = true,
        bool $isolated = false
    ): array {
        if (is_array($tier)) {
            $isolated = $fix;
            $fix = (bool) $only;
            $only = $tier;
            $tier = 'deep';
        }

        $results = [];

        // If fix or isolated mode is requested, run sequentially to avoid conflicts
        if ($fix || $isolated || count($packagePaths) <= 1) {
            foreach ($packagePaths as $key => $path) {
                $packageName = is_string($key) && ! is_numeric($key) ? $key : null;
                $results[$path] = $this->checkAll($path, $packageName, $tier, $only, $fix, $isolated);
            }

            return $results;
        }

        $tasks = [];
        $checkResults = [];

        $baseChecks = match ($tier) {
            'quick' => ['composer', 'pint'],
            'deep' => ['composer', 'pint', 'phpstan', 'tests'],
            default => ['composer', 'pint', 'phpstan', 'tests'],
        };

        if (in_array('isolated', $only, true)) {
            $baseChecks[] = 'isolated';
        }

        if (! empty($only)) {
            $baseChecks = array_values(array_intersect($baseChecks, $only));
        }

        foreach ($packagePaths as $key => $path) {
            $absPackagePath = $this->normalizePath($path);
            $packageName = is_string($key) && ! is_numeric($key) ? $key : $this->resolvePackageName($absPackagePath);
            $checkResults[$path] = [];

            foreach ($baseChecks as $check) {
                $task = $this->buildCheckTask($absPackagePath, $packageName, $check);
                $taskId = $packageName.':'.$check;

                if (! empty($task['failed'])) {
                    $checkResults[$path][] = new CheckResult(
                        check: $check,
                        package: $packageName,
                        status: 'failed',
                        output: $task['output'],
                        durationSeconds: 0.0,
                    );
                } elseif (! empty($task['skipped'])) {
                    $checkResults[$path][] = new CheckResult(
                        check: $check,
                        package: $packageName,
                        status: 'skipped',
                        output: $task['output'],
                        durationSeconds: 0.0,
                    );
                } else {
                    $tasks[$taskId] = array_merge($task, [
                        'packagePath' => $path,
                        'packageName' => $packageName,
                        'check' => $check,
                    ]);
                }
            }
        }

        if (! empty($tasks)) {
            $startTime = microtime(true);

            $poolResults = Process::pool(function ($pool) use ($tasks) {
                foreach ($tasks as $taskId => $task) {
                    $p = $pool->as($taskId)->path($task['cwd'])->timeout($task['timeout']);
                    if (! empty($task['env'])) {
                        $p = $p->env($task['env']);
                    }
                    $p->command($task['command']);
                }
            })->wait();

            $poolCollection = $poolResults->collect();
            $duration = round(microtime(true) - $startTime, 3);

            foreach ($tasks as $taskId => $task) {
                /** @var ProcessResult|null $processResult */
                $processResult = $poolCollection->get($taskId);
                $exitCode = $processResult ? ($processResult->exitCode() ?? 1) : 1;
                $output = $processResult ? trim($processResult->output()."\n".$processResult->errorOutput()) : 'Process did not return output.';
                $status = ($exitCode === 0) ? 'passed' : 'failed';

                $checkResults[$task['packagePath']][] = new CheckResult(
                    check: $task['check'],
                    package: $task['packageName'],
                    status: $status,
                    output: $output,
                    durationSeconds: $duration,
                );
            }
        }

        return $checkResults;
    }

    /**
     * Build check execution parameters or detect skipped state.
     *
     * @return array{skipped: bool, failed?: bool, output: string, command: array<int, string>, cwd: string, timeout: int, env: array<string, string>}
     */
    protected function buildCheckTask(string $absPackagePath, string $packageName, string $check): array
    {
        $default = [
            'skipped' => false,
            'failed' => false,
            'output' => '',
            'command' => [],
            'cwd' => base_path(),
            'timeout' => 120,
            'env' => [],
        ];

        return match ($check) {
            'composer' => $this->buildComposerTask($absPackagePath, $packageName, $default),
            'pint' => $this->buildPintTask($absPackagePath, $packageName, $default),
            'phpstan' => $this->buildPhpstanTask($absPackagePath, $packageName, $default),
            'tests' => $this->buildTestsTask($absPackagePath, $packageName, $default),
            default => array_merge($default, ['skipped' => true, 'output' => "Unknown check [{$check}]."]),
        };
    }

    /**
     * @param  array{skipped: bool, failed?: bool, output: string, command: array<int, string>, cwd: string, timeout: int, env: array<string, string>}  $task
     * @return array{skipped: bool, failed?: bool, output: string, command: array<int, string>, cwd: string, timeout: int, env: array<string, string>}
     */
    protected function buildComposerTask(string $absPackagePath, string $packageName, array $task): array
    {
        $composerPath = $absPackagePath.DIRECTORY_SEPARATOR.'composer.json';
        if (! File::exists($composerPath)) {
            return array_merge($task, [
                'failed' => true,
                'output' => 'composer.json not found in package directory.',
            ]);
        }

        $relComposer = str_replace('\\', '/', ltrim(str_replace(base_path(), '', $composerPath), '/\\'));

        return array_merge($task, [
            'command' => ['composer', 'validate', '--strict', $relComposer],
        ]);
    }

    /**
     * @param  array{skipped: bool, failed?: bool, output: string, command: array<int, string>, cwd: string, timeout: int, env: array<string, string>}  $task
     * @return array{skipped: bool, failed?: bool, output: string, command: array<int, string>, cwd: string, timeout: int, env: array<string, string>}
     */
    protected function buildPintTask(string $absPackagePath, string $packageName, array $task): array
    {
        $pintBin = $this->resolveBinary('pint');
        if ($pintBin === null) {
            return array_merge($task, [
                'failed' => true,
                'output' => 'Pint binary not found in vendor/bin. Run "composer require --dev laravel/pint" on the host.',
            ]);
        }

        $relPath = str_replace('\\', '/', ltrim(str_replace(base_path(), '', $absPackagePath), '/\\'));

        return array_merge($task, [
            'command' => [$pintBin, $relPath, '--test'],
        ]);
    }

    /**
     * @param  array{skipped: bool, failed?: bool, output: string, command: array<int, string>, cwd: string, timeout: int, env: array<string, string>}  $task
     * @return array{skipped: bool, failed?: bool, output: string, command: array<int, string>, cwd: string, timeout: int, env: array<string, string>}
     */
    protected function buildPhpstanTask(string $absPackagePath, string $packageName, array $task): array
    {
        $phpstanBin = $this->resolveBinary('phpstan');
        if ($phpstanBin === null) {
            return array_merge($task, [
                'failed' => true,
                'output' => 'PHPStan binary not found in vendor/bin. Run "composer require --dev phpstan/phpstan" on the host.',
            ]);
        }

        if (! File::isDirectory($absPackagePath.DIRECTORY_SEPARATOR.'src')) {
            return array_merge($task, [
                'skipped' => true,
                'output' => 'No src/ directory found in package.',
            ]);
        }

        $relPath = str_replace('\\', '/', ltrim(str_replace(base_path(), '', $absPackagePath), '/\\'));
        $neonConfig = null;
        foreach (['phpstan.neon', 'phpstan.neon.dist'] as $cfg) {
            if (File::exists($absPackagePath.DIRECTORY_SEPARATOR.$cfg)) {
                $neonConfig = $relPath.'/'.$cfg;
                break;
            }
        }

        $command = [$phpstanBin, 'analyse'];
        if ($neonConfig !== null) {
            $command[] = '--configuration='.$neonConfig;
        } else {
            $command[] = $relPath.'/src';
            $command[] = '--level='.self::DEFAULT_PHPSTAN_LEVEL;
        }
        $command[] = '--memory-limit=1G';

        return array_merge($task, [
            'command' => $command,
        ]);
    }

    /**
     * @param  array{skipped: bool, failed?: bool, output: string, command: array<int, string>, cwd: string, timeout: int, env: array<string, string>}  $task
     * @return array{skipped: bool, failed?: bool, output: string, command: array<int, string>, cwd: string, timeout: int, env: array<string, string>}
     */
    protected function buildTestsTask(string $absPackagePath, string $packageName, array $task): array
    {
        $phpunitXml = null;
        foreach (['phpunit.xml', 'phpunit.xml.dist'] as $cfg) {
            if (File::exists($absPackagePath.DIRECTORY_SEPARATOR.$cfg)) {
                $phpunitXml = $cfg;
                break;
            }
        }

        if ($phpunitXml === null) {
            return array_merge($task, [
                'skipped' => true,
                'output' => 'No phpunit.xml or phpunit.xml.dist found in package directory.',
            ]);
        }

        $relPath = str_replace('\\', '/', ltrim(str_replace(base_path(), '', $absPackagePath), '/\\'));
        $xmlRel = $relPath.'/'.$phpunitXml;

        $pestBin = $this->resolveBinary('pest');
        $phpunitBin = $this->resolveBinary('phpunit');
        $isPest = File::exists($absPackagePath.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Pest.php') && $pestBin !== null;

        if ($isPest) {
            $command = [$pestBin, '-c', $xmlRel];
        } elseif ($phpunitBin !== null) {
            $command = [$phpunitBin, '-c', $xmlRel];
        } else {
            $artisan = base_path('artisan');
            if (File::exists($artisan)) {
                $command = [PHP_BINARY, 'artisan', 'test', '-c', $xmlRel];
            } else {
                return array_merge($task, [
                    'failed' => true,
                    'output' => 'No test runner (phpunit or pest) found in vendor/bin. Run "composer require --dev phpunit/phpunit" on the host.',
                ]);
            }
        }

        $command[] = '--fail-on-empty-test-suite';

        return array_merge($task, [
            'command' => $command,
            'env' => [
                'APP_ENV' => 'testing',
                'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'MAIL_MAILER' => 'array',
            ],
        ]);
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
        } catch (\Throwable $e) {
            $exitCode = 1;
            $output = 'Process execution failed: '.$e->getMessage();
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
}
