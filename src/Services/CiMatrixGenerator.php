<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;

class CiMatrixGenerator
{
    /**
     * Standard candidate PHP versions evaluated for matrix generation.
     *
     * @var array<int, string>
     */
    public const SUPPORTED_PHP_VERSIONS = ['8.2', '8.3', '8.4'];

    /**
     * Standard candidate Laravel versions evaluated for matrix generation.
     *
     * @var array<int, string>
     */
    public const SUPPORTED_LARAVEL_VERSIONS = ['11.*', '12.*', '13.*'];

    /**
     * Mapping of Laravel version to minimum compatible PHP version.
     *
     * @var array<string, string>
     */
    public const LARAVEL_PHP_REQUIREMENTS = [
        '11.*' => '8.2',
        '12.*' => '8.2',
        '13.*' => '8.3',
    ];

    /**
     * Mapping of Laravel version to compatible Orchestra Testbench version.
     *
     * @var array<string, string>
     */
    public const TESTBENCH_MAP = [
        '11.*' => '9.*',
        '12.*' => '10.*',
        '13.*' => '10.*',
    ];

    public function __construct(
        protected GitInspector $gitInspector
    ) {}

    /**
     * Resolve compatible PHP versions from a composer constraint string.
     *
     * @return array<int, string>
     */
    public function resolvePhpVersions(?string $constraint): array
    {
        if ($constraint === null || trim($constraint) === '') {
            return self::SUPPORTED_PHP_VERSIONS;
        }

        $resolved = [];
        foreach (self::SUPPORTED_PHP_VERSIONS as $version) {
            if ($this->satisfiesVersion($version, $constraint)) {
                $resolved[] = $version;
            }
        }

        return ! empty($resolved) ? $resolved : self::SUPPORTED_PHP_VERSIONS;
    }

    /**
     * Resolve compatible Laravel versions from a composer constraint string.
     *
     * @return array<int, string>
     */
    public function resolveLaravelVersions(?string $constraint): array
    {
        if ($constraint === null || trim($constraint) === '') {
            return self::SUPPORTED_LARAVEL_VERSIONS;
        }

        $resolved = [];
        foreach (self::SUPPORTED_LARAVEL_VERSIONS as $version) {
            $normalizedVersion = rtrim($version, '.*');
            if ($this->satisfiesVersion($normalizedVersion, $constraint)) {
                $resolved[] = $version;
            }
        }

        return ! empty($resolved) ? $resolved : self::SUPPORTED_LARAVEL_VERSIONS;
    }

    /**
     * Generate matrix specification for a single package.
     *
     * @return array{
     *     os: array<int, string>,
     *     php: array<int, string>,
     *     laravel: array<int, string>,
     *     dependency-version: array<int, string>,
     *     include: array<int, array{laravel: string, testbench: string}>,
     *     exclude?: array<int, array{php: string, laravel: string}>
     * }
     */
    public function generatePackageMatrix(string $packagePath): array
    {
        $manifest = $this->loadComposerJson($packagePath);
        $phpConstraint = $manifest['require']['php'] ?? null;
        $illuminateConstraint = $manifest['require']['illuminate/support']
            ?? $manifest['require']['laravel/framework']
            ?? null;

        $phpVersions = $this->resolvePhpVersions(is_string($phpConstraint) ? $phpConstraint : null);
        $laravelVersions = $this->resolveLaravelVersions(is_string($illuminateConstraint) ? $illuminateConstraint : null);

        $include = [];
        foreach ($laravelVersions as $laravelVersion) {
            $testbench = self::TESTBENCH_MAP[$laravelVersion] ?? '10.*';
            $include[] = [
                'laravel' => $laravelVersion,
                'testbench' => $testbench,
            ];
        }

        $exclude = [];
        foreach ($phpVersions as $php) {
            foreach ($laravelVersions as $laravel) {
                $minPhp = self::LARAVEL_PHP_REQUIREMENTS[$laravel] ?? '8.2';
                if (version_compare($php, $minPhp, '<')) {
                    $exclude[] = [
                        'php' => $php,
                        'laravel' => $laravel,
                    ];
                }
            }
        }

        $matrix = [
            'os' => ['ubuntu-latest'],
            'php' => $phpVersions,
            'laravel' => $laravelVersions,
            'dependency-version' => ['prefer-stable'],
            'include' => $include,
        ];

        if (! empty($exclude)) {
            $matrix['exclude'] = $exclude;
        }

        return $matrix;
    }

    /**
     * Generate full workflow YAML for a single package.
     */
    public function generatePackageWorkflowYaml(string $packagePath, ?string $workflowName = 'run-tests'): string
    {
        $matrix = $this->generatePackageMatrix($packagePath);

        $workflow = [
            'name' => $workflowName,
            'on' => [
                'push' => ['branches' => ['main', 'master']],
                'pull_request' => ['branches' => ['main', 'master']],
            ],
            'jobs' => [
                'test' => [
                    'runs-on' => '${{ matrix.os }}',
                    'strategy' => [
                        'fail-fast' => true,
                        'matrix' => $matrix,
                    ],
                    'name' => 'P${{ matrix.php }} - L${{ matrix.laravel }} - ${{ matrix.dependency-version }} - ${{ matrix.os }}',
                    'steps' => [
                        [
                            'name' => 'Checkout code',
                            'uses' => 'actions/checkout@v4',
                        ],
                        [
                            'name' => 'Setup PHP',
                            'uses' => 'shivammathur/setup-php@v2',
                            'with' => [
                                'php-version' => '${{ matrix.php }}',
                                'extensions' => 'dom, curl, libxml, mbstring, zip, pcntl, pdo, sqlite, pdo_sqlite, bcmath, soap, intl, gd, exif, iconv',
                                'coverage' => 'none',
                            ],
                        ],
                        [
                            'name' => 'Install dependencies',
                            'run' => "composer require \"laravel/framework:\${{ matrix.laravel }}\" \"orchestra/testbench:\${{ matrix.testbench }}\" --no-interaction --no-update\ncomposer update --\${{ matrix.dependency-version }} --prefer-dist --no-interaction",
                        ],
                        [
                            'name' => 'Execute tests',
                            'run' => 'vendor/bin/phpunit',
                        ],
                    ],
                ],
            ],
        ];

        return Yaml::dump($workflow, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    /**
     * Generate dynamic GitHub Actions matrix structure across workspace packages.
     *
     * @param  array<int, string>  $onlyPackages  Filter to specific canonical package names or relative paths
     * @return array{include: array<int, array{package: string, path: string, php: string, laravel: string, testbench: string}>}
     */
    public function generateWorkspaceMatrix(array $onlyPackages = []): array
    {
        $manifestWorkspaces = Workspace::all();
        $packages = [];

        foreach ($manifestWorkspaces as $wsPath => $wsConfig) {
            $wsVendor = $wsConfig['vendor'] ?? null;
            foreach ($wsConfig['packages'] ?? [] as $pkg) {
                $pkgName = is_array($pkg) ? ($pkg['name'] ?? '') : (string) $pkg;
                $alias = is_array($pkg) ? ($pkg['alias'] ?? null) : null;

                if ($pkgName === '') {
                    continue;
                }

                $effectiveDir = $alias ?? $pkgName;
                $relPath = str_replace('\\', '/', $wsPath.'/'.$effectiveDir);
                $canonicalName = $wsVendor !== null && ! str_contains($pkgName, '/')
                    ? "{$wsVendor}/{$pkgName}"
                    : $pkgName;

                $packages[$canonicalName] = $relPath;
            }
        }

        $items = [];
        foreach ($packages as $canonical => $relPath) {
            if (! empty($onlyPackages) && ! in_array($canonical, $onlyPackages, true) && ! in_array($relPath, $onlyPackages, true)) {
                continue;
            }

            $absPath = base_path($relPath);
            if (! File::isDirectory($absPath) || ! File::exists($absPath.DIRECTORY_SEPARATOR.'composer.json')) {
                continue;
            }

            $matrix = $this->generatePackageMatrix($absPath);
            $phpVersions = $matrix['php'];
            $laravelVersions = $matrix['laravel'];
            $excludes = $matrix['exclude'] ?? [];

            foreach ($laravelVersions as $laravel) {
                $testbench = self::TESTBENCH_MAP[$laravel] ?? '10.*';
                foreach ($phpVersions as $php) {
                    $isExcluded = false;
                    foreach ($excludes as $exc) {
                        if ($exc['php'] === $php && $exc['laravel'] === $laravel) {
                            $isExcluded = true;
                            break;
                        }
                    }

                    if ($isExcluded) {
                        continue;
                    }

                    $items[] = [
                        'package' => $canonical,
                        'path' => $relPath,
                        'php' => $php,
                        'laravel' => $laravel,
                        'testbench' => $testbench,
                    ];
                }
            }
        }

        return ['include' => $items];
    }

    /**
     * Generate complete host workspace workflow YAML.
     *
     * @param  array<int, string>  $onlyPackages
     */
    public function generateWorkspaceWorkflowYaml(array $onlyPackages = []): string
    {
        $matrix = $this->generateWorkspaceMatrix($onlyPackages);

        $workflow = [
            'name' => 'packages-ci',
            'on' => [
                'push' => ['branches' => ['main', 'master']],
                'pull_request' => ['branches' => ['main', 'master']],
            ],
            'jobs' => [
                'test' => [
                    'runs-on' => 'ubuntu-latest',
                    'strategy' => [
                        'fail-fast' => false,
                        'matrix' => [
                            'include' => $matrix['include'],
                        ],
                    ],
                    'name' => '${{ matrix.package }} (PHP ${{ matrix.php }} - L${{ matrix.laravel }})',
                    'steps' => [
                        [
                            'name' => 'Checkout code',
                            'uses' => 'actions/checkout@v4',
                        ],
                        [
                            'name' => 'Setup PHP',
                            'uses' => 'shivammathur/setup-php@v2',
                            'with' => [
                                'php-version' => '${{ matrix.php }}',
                                'extensions' => 'dom, curl, libxml, mbstring, zip, pcntl, pdo, sqlite, pdo_sqlite, bcmath, soap, intl, gd, exif, iconv',
                                'coverage' => 'none',
                            ],
                        ],
                        [
                            'name' => 'Restore Workspace Packages',
                            'run' => 'php workspace restore',
                        ],
                        [
                            'name' => 'Install Dependencies',
                            'run' => "composer require \"laravel/framework:\${{ matrix.laravel }}\" \"orchestra/testbench:\${{ matrix.testbench }}\" --no-interaction --no-update\ncomposer update --prefer-stable --prefer-dist --no-interaction",
                        ],
                        [
                            'name' => 'Execute Package Tests',
                            'run' => 'vendor/bin/phpunit -c ${{ matrix.path }}/phpunit.xml.dist',
                        ],
                    ],
                ],
            ],
        ];

        return Yaml::dump($workflow, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    /**
     * Detect packages that have changed files compared to a base git branch or working tree.
     *
     * @return array<int, string>
     */
    public function detectChangedPackages(string $baseRef = 'origin/main'): array
    {
        $diffProcess = Process::path(base_path())->run(['git', 'diff', '--name-only', "{$baseRef}...HEAD"]);
        if (! $diffProcess->successful()) {
            $diffProcess = Process::path(base_path())->run(['git', 'diff', '--name-only', 'HEAD~1']);
        }

        $changedFiles = $diffProcess->successful()
            ? array_filter(explode("\n", trim(str_replace("\r", '', $diffProcess->output()))))
            : [];

        // Also check unstaged and staged files
        $statusProcess = Process::path(base_path())->run(['git', 'status', '--porcelain']);
        if ($statusProcess->successful()) {
            foreach (explode("\n", trim(str_replace("\r", '', $statusProcess->output()))) as $line) {
                if (strlen($line) > 3) {
                    $changedFiles[] = trim(substr($line, 3));
                }
            }
        }

        $changedFiles = array_unique(array_filter($changedFiles));

        $manifestWorkspaces = Workspace::all();
        $changedPackages = [];

        foreach ($manifestWorkspaces as $wsPath => $wsConfig) {
            $cleanWs = trim(str_replace('\\', '/', $wsPath), '/');
            foreach ($wsConfig['packages'] ?? [] as $pkg) {
                $pkgName = is_array($pkg) ? ($pkg['name'] ?? '') : (string) $pkg;
                $alias = is_array($pkg) ? ($pkg['alias'] ?? null) : null;
                $effectiveDir = $alias ?? $pkgName;
                $pkgPrefix = "{$cleanWs}/{$effectiveDir}/";

                foreach ($changedFiles as $file) {
                    $normFile = str_replace('\\', '/', $file);
                    if (str_starts_with($normFile, $pkgPrefix)) {
                        $canonicalName = ($wsConfig['vendor'] ?? null) !== null && ! str_contains($pkgName, '/')
                            ? "{$wsConfig['vendor']}/{$pkgName}"
                            : $pkgName;
                        $changedPackages[] = $canonicalName;
                        break;
                    }
                }
            }
        }

        return array_values(array_unique($changedPackages));
    }

    /**
     * Load composer.json from a given directory.
     *
     * @return array<string, mixed>
     */
    protected function loadComposerJson(string $packagePath): array
    {
        $composerPath = rtrim($packagePath, '/\\').DIRECTORY_SEPARATOR.'composer.json';
        if (! File::exists($composerPath)) {
            return [];
        }

        try {
            $data = json_decode(File::get($composerPath), true, 512, JSON_THROW_ON_ERROR);

            return is_array($data) ? $data : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Evaluate if a specific version (e.g. 8.2 or 11.*) satisfies a composer-like constraint.
     */
    protected function satisfiesVersion(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        // Split OR clauses (| or ||)
        $orClauses = preg_split('/\s*\|\|\s*|\s*\|\s*/', $constraint) ?: [];
        foreach ($orClauses as $clause) {
            $clause = trim($clause);
            if ($clause === '') {
                continue;
            }

            if ($this->satisfiesAndClause($version, $clause)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Evaluate AND clause (comma-separated or space-separated conditions).
     */
    protected function satisfiesAndClause(string $version, string $clause): bool
    {
        $parts = preg_split('/[\s,]+/', trim($clause)) ?: [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (! $this->satisfiesAtomicCondition($version, $part)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Evaluate single condition (e.g. ^8.2, >=8.3, ~8.2.0, 8.2.*, ^11.0).
     */
    protected function satisfiesAtomicCondition(string $version, string $condition): bool
    {
        $targetVersion = trim($version, '.*');
        $normVersion = $this->normalizeToSemver($targetVersion);

        // Wildcards: e.g. 8.2.* or 11.*
        if (str_ends_with($condition, '.*')) {
            $prefix = substr($condition, 0, -2);

            return $targetVersion === $prefix || str_starts_with($normVersion, $prefix.'.');
        }

        // Caret ^: e.g. ^8.2 means >= 8.2.0 and < 9.0.0; ^11.0 means >= 11.0.0 and < 12.0.0
        if (str_starts_with($condition, '^')) {
            $min = substr($condition, 1);
            $minNorm = $this->normalizeToSemver($min);

            [$major] = explode('.', $minNorm);
            $nextMajor = ((int) $major + 1).'.0.0';

            return version_compare($normVersion, $minNorm, '>=') && version_compare($normVersion, $nextMajor, '<');
        }

        // Tilde ~: e.g. ~8.2 means >= 8.2.0 and < 9.0.0, ~8.2.0 means >= 8.2.0 and < 8.3.0
        if (str_starts_with($condition, '~')) {
            $min = substr($condition, 1);
            $dotCount = substr_count(trim($min), '.');
            $minNorm = $this->normalizeToSemver($min);

            $segments = explode('.', $minNorm);
            if ($dotCount <= 1) {
                $max = ((int) $segments[0] + 1).'.0.0';
            } else {
                $max = $segments[0].'.'.((int) $segments[1] + 1).'.0';
            }

            return version_compare($normVersion, $minNorm, '>=') && version_compare($normVersion, $max, '<');
        }

        // Operators: >=, <=, >, <, ==, =
        if (preg_match('/^(>=|<=|>|<|==|=)\s*(.+)$/', $condition, $matches)) {
            $op = $matches[1] === '==' ? '=' : $matches[1];
            $targetNorm = $this->normalizeToSemver($matches[2]);

            return version_compare($normVersion, $targetNorm, $op);
        }

        // Exact match
        $condNorm = $this->normalizeToSemver($condition);

        return version_compare($normVersion, $condNorm, '=');
    }

    /**
     * Normalize partial version numbers (e.g. '11', '8.2', '8.2.3') to a 3-part semver string.
     */
    protected function normalizeToSemver(string $v): string
    {
        $clean = trim($v, '. ');
        $parts = explode('.', $clean);

        while (count($parts) < 3) {
            $parts[] = '0';
        }

        return implode('.', array_slice($parts, 0, 3));
    }
}
