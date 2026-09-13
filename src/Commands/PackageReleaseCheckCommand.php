<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\ReleaseGateResult;
use AlexKassel\WorkspaceDevelopmentToolkit\Enums\CheckStatus;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerManager;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ReleaseChecker;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceManager;
use RuntimeException;

class PackageReleaseCheckCommand extends BasePackageCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:release-check
        {package : Package name in vendor/package format (e.g. acme/my-pkg) or relative package path}
        {--fast : Skip isolated standalone installation check for rapid verification}
        {--json : Output machine-readable JSON summary}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run pre-flight release-gate checks (clean tree, audit freshness, quality, README)';

    public function __construct(
        WorkspaceManager $workspace,
        ComposerManager $composer,
        protected readonly ReleaseChecker $checker,
    ) {
        parent::__construct($workspace, $composer);
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $rawPackage = (string) $this->argument('package');
        $fast = (bool) $this->option('fast');
        $isJson = (bool) $this->option('json');

        try {
            $result = $this->checker->check($rawPackage, $fast);
        } catch (RuntimeException $e) {
            if ($isJson) {
                $this->line(json_encode(['status' => 'error', 'error' => $e->getMessage()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error('Error: '.$e->getMessage());
            }

            return self::FAILURE;
        }

        if ($isJson) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $result->exitCode();
        }

        $this->info("RELEASE-GATE PRE-FLIGHT [{$result->package}] ({$result->path})");
        $this->line("  <comment>Tag:</comment> {$result->latestTag}");
        $this->newLine();

        foreach ($result->checks as $check) {
            $badge = CheckStatus::format($check['status'], bracketed: true);

            $this->line("  {$badge} <options=bold>{$check['name']}</>");
            if ($check['status'] !== 'passed') {
                $this->line("      └─ {$check['message']}");
            }
        }

        $this->newLine();

        if ($result->isReady()) {
            $this->info('  ✔ RELEASE GATE: READY TO PUBLISH');

            return self::SUCCESS;
        }

        if ($result->isActionRequired()) {
            $this->warn('  ▲ RELEASE GATE: ACTION / DECISION REQUIRED');

            return ReleaseGateResult::EXIT_ACTION_REQUIRED;
        }

        $this->error('  ✖ RELEASE-GATE: BLOCKED (fix failing checks before release)');

        return self::FAILURE;
    }
}
