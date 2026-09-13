<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\CheckResult;
use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\CertificateVerifier;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\PackageAuditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

class PackageAuditCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:audit
        {name? : Package name, alias or directory path}
        {--verify : Verify an existing audit certificate}
        {--json : Output machine-readable JSON}
        {--target-version= : Explicit release version for the certificate}
        {--no-commit : Skip git tag creation and committing AUDIT.json}
        {--no-tag : Do not create git tag during commit}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Audit and certify a package with deterministic cryptographic verification';

    public function __construct(
        protected PackageAuditor $auditor,
        protected CertificateVerifier $verifier,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $rawName = (string) $this->argument('name');

        if ($rawName === '') {
            $this->error('Please specify a package name, alias, or path.');
            $this->line('  <comment>How to fix:</comment> Provide a package name:');
            $this->line('  <info>php artisan package:audit vendor/package</info>');
            $this->line('  <info>php artisan package:audit vendor/package --verify</info>');

            return self::FAILURE;
        }

        // Verify package existence
        $path = Workspace::findPackagePath($rawName);
        if ($path === null && ! File::isDirectory(base_path($rawName)) && ! File::isDirectory($rawName)) {
            $this->error("Package [{$rawName}] not found.");
            $this->line('  <comment>How to fix:</comment> View registered packages using:');
            $this->line('  <info>php artisan workspace:list</info>');

            return self::FAILURE;
        }

        if ($this->option('verify')) {
            return $this->handleVerify($rawName);
        }

        return $this->handleAudit($rawName);
    }

    /**
     * Handle audit execution and certificate generation.
     */
    protected function handleAudit(string $name): int
    {
        $targetVersion = is_string($this->option('target-version')) && $this->option('target-version') !== ''
            ? (string) $this->option('target-version')
            : null;

        $noCommit = (bool) $this->option('no-commit');
        $noTag = (bool) $this->option('no-tag');

        try {
            $report = $this->auditor->audit($name, $targetVersion, $noCommit, $noTag);
        } catch (Throwable $e) {
            if ($this->option('json')) {
                $this->line((string) json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error('Audit failed with error: '.$e->getMessage());
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line($report->toJson());

            return $report->allPassed() ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();
        $this->info("Auditing package [{$report->package}] (v{$report->version})");
        $this->line("  <fg=gray>Commit:    {$report->commit}</>");
        $this->line("  <fg=gray>Tree Hash: {$report->treeHash}</>");
        $this->line("  <fg=gray>Branch:    {$report->branch}</>");
        $this->newLine();

        $rows = [];
        foreach ($report->checks as $checkKey => $check) {
            $rows[] = [
                strtoupper($checkKey),
                $this->formatStatus($check->status),
                number_format($check->durationSeconds, 2).'s',
                $this->summarizeOutput($check),
            ];
        }

        $this->table(['Check', 'Status', 'Duration', 'Details'], $rows);

        $this->newLine();

        if ($report->allPassed()) {
            $this->info("✔ Package [{$report->package}] passed all audit checks!");
            $this->line("  <fg=cyan>Fingerprint:</> {$report->fingerprint}");

            if (! $noCommit) {
                $this->line("  <fg=green>Certificate:</> AUDIT.json issued and committed (tag: audit/v{$report->version}).");
            } else {
                $this->line('  <fg=yellow>Dry-run mode:</> AUDIT.json was not committed (--no-commit).');
            }

            return self::SUCCESS;
        }

        $this->error("✖ Package [{$report->package}] failed one or more audit checks.");

        foreach ($report->checks as $checkKey => $check) {
            if ($check->isFailed() && trim($check->output) !== '') {
                $this->newLine();
                $this->error("Failure details for [{$checkKey}]:");
                $this->line($check->output);
            }
        }

        return self::FAILURE;
    }

    /**
     * Handle certificate verification.
     */
    protected function handleVerify(string $name): int
    {
        try {
            $result = $this->verifier->verify($name);
        } catch (Throwable $e) {
            if ($this->option('json')) {
                $this->line((string) json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error('Verification failed with error: '.$e->getMessage());
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line($result->toJson());

            return $result->verified ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();
        $this->line("Verifying audit certificate for package [{$name}]...");
        $this->newLine();

        if ($result->verified) {
            $this->info("✔ Audit Certificate VERIFIED for package [{$name}].");
            if ($result->certificate !== null) {
                $this->line("  <fg=gray>Certified Version:</> {$result->certificate->version}");
                $this->line("  <fg=gray>Certified Commit:</>  {$result->certificate->commit}");
                $this->line("  <fg=gray>Tree Hash:</>         {$result->certificate->treeHash}");
                $this->line("  <fg=gray>Fingerprint:</>       {$result->certificate->fingerprint}");
            }

            return self::SUCCESS;
        }

        $this->error("✖ Audit Certificate verification failed: [{$result->status}]");
        if ($result->reason !== null) {
            $this->line("  <comment>Reason:</comment> {$result->reason}");
        }

        return self::FAILURE;
    }

    protected function formatStatus(string $status): string
    {
        return match (strtolower($status)) {
            'passed' => '<fg=green>PASS</>',
            'failed' => '<fg=red>FAIL</>',
            'skipped' => '<fg=yellow>SKIP</>',
            default => $status,
        };
    }

    protected function summarizeOutput(CheckResult $check): string
    {
        if ($check->isPassed()) {
            return 'OK';
        }

        $firstLine = trim(explode("\n", trim($check->output))[0]);

        return mb_strimwidth($firstLine, 0, 50, '...');
    }
}
