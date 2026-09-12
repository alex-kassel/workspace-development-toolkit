<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\CheckResult;
use FilesystemIterator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

class IsolatedPackageVerifier
{
    public function __construct(
        protected FilesystemHelper $filesystem
    ) {}

    /**
     * Run isolated package verification in a clean temporary directory.
     */
    public function verify(string $packagePath, ?string $packageName = null): CheckResult
    {
        $startTime = microtime(true);
        $packageName ??= $this->resolvePackageName($packagePath);

        $temporary = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wdt-isolated-'.bin2hex(random_bytes(12));
        $project = $temporary.DIRECTORY_SEPARATOR.'project';
        $output = [];
        $exitCode = 0;

        try {
            File::ensureDirectoryExists($project);
            $this->export($packagePath, $project);

            $composerJsonPath = $project.DIRECTORY_SEPARATOR.'composer.json';
            if (! File::exists($composerJsonPath)) {
                throw new RuntimeException('Package composer.json not found in exported project.');
            }

            $manifest = json_decode(File::get($composerJsonPath), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($manifest)) {
                throw new RuntimeException('Package composer.json must contain an object.');
            }

            foreach (($manifest['repositories'] ?? []) as $repository) {
                if (is_array($repository) && ($repository['type'] ?? '') === 'path') {
                    throw new RuntimeException('Standalone verification rejects path repositories; dependencies must be independently installable.');
                }
            }

            $configuration = File::exists($project.DIRECTORY_SEPARATOR.'phpunit.xml') ? 'phpunit.xml' : 'phpunit.xml.dist';
            if (! File::exists($project.DIRECTORY_SEPARATOR.$configuration)) {
                throw new RuntimeException('Standalone verification requires phpunit.xml or phpunit.xml.dist.');
            }

            $environment = [
                'COMPOSER_HOME' => $temporary.DIRECTORY_SEPARATOR.'composer-home',
                'COMPOSER_VENDOR_DIR' => $project.DIRECTORY_SEPARATOR.'vendor',
                'COMPOSER_BIN_DIR' => $project.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'bin',
                'APP_ENV' => 'testing',
                'CACHE_STORE' => 'array',
                'CACHE_DRIVER' => 'array',
                'SESSION_DRIVER' => 'array',
                'QUEUE_CONNECTION' => 'sync',
                'MAIL_MAILER' => 'array',
            ];

            $cacheDir = $this->resolveComposerCacheDir();
            if ($cacheDir !== null) {
                $environment['COMPOSER_CACHE_DIR'] = $cacheDir;
            }

            $output[] = $this->run(
                ['composer', 'install', '--prefer-dist', '--no-interaction', '--no-progress'],
                $project,
                $environment
            );

            $binary = File::exists($project.DIRECTORY_SEPARATOR.'tests'.DIRECTORY_SEPARATOR.'Pest.php') ? 'pest' : 'phpunit';
            $binaryPath = $this->resolveLocalBinary($project.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'bin', $binary);

            if ($binaryPath === null) {
                throw new RuntimeException("Declare the test runner in package require-dev; the isolated vendor has no {$binary}.");
            }

            $output[] = $this->run(
                [PHP_BINARY, $binaryPath, '-c', $configuration, '--fail-on-empty-test-suite'],
                $project,
                $environment
            );
        } catch (Throwable $e) {
            $exitCode = 1;
            $output[] = $e->getMessage();
        } finally {
            try {
                $this->filesystem->deleteDirectoryRecursively($temporary);
            } catch (Throwable $cleanupError) {
                $output[] = 'Cleanup warning: '.$cleanupError->getMessage();
            }
        }

        $duration = round(microtime(true) - $startTime, 3);
        $status = ($exitCode === 0) ? 'passed' : 'failed';

        return new CheckResult(
            check: 'isolated',
            package: $packageName,
            status: $status,
            output: implode("\n", array_filter($output)),
            durationSeconds: $duration,
        );
    }

    /**
     * Export tracked and non-ignored files to destination.
     */
    public function export(string $packagePath, string $destination): void
    {
        $source = realpath($packagePath);
        if ($source === false || ! is_dir($source)) {
            $source = base_path($packagePath);
            if (! is_dir($source)) {
                throw new RuntimeException("Package directory does not exist: {$packagePath}");
            }
        }

        $result = Process::path($source)->run(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z']);
        $listed = $result->successful() ? $result->output() : '';

        if ($listed !== '') {
            $files = array_unique(explode("\0", $listed));
            foreach ($files as $relative) {
                $relative = trim($relative);
                if ($relative === '' || preg_match('~(^|/)(?:vendor|node_modules|\.git|\.phpunit\.cache)(/|$)~', $relative)
                    || $relative === 'composer.lock' || $relative === '.env') {
                    continue;
                }

                if (str_starts_with($relative, '/') || in_array('..', explode('/', str_replace('\\', '/', $relative)), true)) {
                    throw new RuntimeException('Invalid export path: '.$relative);
                }

                $fullSource = $source.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if (! file_exists($fullSource) && ! is_link($fullSource)) {
                    continue;
                }

                $target = $destination.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
                File::ensureDirectoryExists(dirname($target));
                copy($fullSource, $target);
            }
        } else {
            $this->copyNonIgnored($source, $destination);
        }
    }

    /**
     * Fallback copy for environments without initialized Git repository.
     */
    protected function copyNonIgnored(string $source, string $destination): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $subPath = substr($item->getPathname(), strlen($source) + 1);
            $normalizedSub = str_replace('\\', '/', $subPath);

            if (preg_match('~(^|/)(?:vendor|node_modules|\.git|\.phpunit\.cache)(/|$)~', $normalizedSub)
                || $normalizedSub === 'composer.lock' || $normalizedSub === '.env') {
                continue;
            }

            $target = $destination.DIRECTORY_SEPARATOR.$subPath;
            if ($item->isDir()) {
                File::ensureDirectoryExists($target);
            } else {
                File::ensureDirectoryExists(dirname($target));
                copy($item->getPathname(), $target);
            }
        }
    }

    /**
     * Resolve test runner binary inside isolated vendor/bin.
     */
    protected function resolveLocalBinary(string $binDir, string $binary): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            foreach (['.bat', '.exe', '.cmd', ''] as $ext) {
                $candidate = $binDir.DIRECTORY_SEPARATOR.$binary.$ext;
                if (File::exists($candidate)) {
                    return $candidate;
                }
            }
        }

        $bin = $binDir.DIRECTORY_SEPARATOR.$binary;
        if (File::exists($bin)) {
            return $bin;
        }

        return null;
    }

    /**
     * Run an isolated command.
     *
     * @param  array<int, string>  $command
     * @param  array<string, string>  $environment
     */
    protected function run(array $command, string $directory, array $environment = []): string
    {
        $pendingProcess = Process::path($directory)->timeout(600);
        if ($environment !== []) {
            $pendingProcess = $pendingProcess->env($environment);
        }

        $result = $pendingProcess->run($command);
        if (! $result->successful()) {
            throw new RuntimeException($result->errorOutput() ?: $result->output());
        }

        return $result->output();
    }

    /**
     * Resolve host Composer cache directory.
     */
    public function resolveComposerCacheDir(): ?string
    {
        $envCache = getenv('COMPOSER_CACHE_DIR');
        if (is_string($envCache) && $envCache !== '' && is_dir($envCache)) {
            return $envCache;
        }

        $home = getenv('COMPOSER_HOME');
        if (is_string($home) && $home !== '' && is_dir($home.'/cache')) {
            return $home.'/cache';
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $localAppData = getenv('LOCALAPPDATA');
            if (is_string($localAppData) && is_dir($localAppData.DIRECTORY_SEPARATOR.'Composer')) {
                return $localAppData.DIRECTORY_SEPARATOR.'Composer';
            }
        } else {
            $homeDir = getenv('HOME');
            if (is_string($homeDir)) {
                if (is_dir($homeDir.'/.cache/composer')) {
                    return $homeDir.'/.cache/composer';
                }
                if (is_dir($homeDir.'/.composer/cache')) {
                    return $homeDir.'/.composer/cache';
                }
            }
        }

        return null;
    }

    /**
     * Resolve package name from composer.json.
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
            } catch (Throwable) {
            }
        }

        $normalized = str_replace('\\', '/', trim($packagePath, '/\\'));
        $parts = explode('/', $normalized);

        if (count($parts) >= 2) {
            return $parts[count($parts) - 2].'/'.$parts[count($parts) - 1];
        }

        return basename($normalized);
    }
}
