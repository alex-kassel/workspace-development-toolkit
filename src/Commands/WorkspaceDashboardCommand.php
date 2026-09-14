<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\WorkspaceDashboardDTO;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerManager;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceDashboardCollector;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceManager;

use function Laravel\Prompts\select;

class WorkspaceDashboardCommand extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workspace:dashboard
        {--workspace= : Filter packages by workspace}
        {--dirty : Show only packages with uncommitted git changes}
        {--json : Output dashboard state as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Interactive TUI Mission Control dashboard for workspace packages';

    public function __construct(
        WorkspaceManager $workspace,
        ComposerManager $composer,
        protected readonly WorkspaceDashboardCollector $collector,
    ) {
        parent::__construct($workspace, $composer);
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $rawWorkspace = (string) $this->option('workspace');
        $filterWorkspace = trim($rawWorkspace) !== '' ? trim($rawWorkspace) : null;
        $onlyDirty = (bool) $this->option('dirty');

        $dto = $this->collector->collect($filterWorkspace, $onlyDirty);

        if ($this->option('json')) {
            foreach (explode("\n", (string) json_encode($dto, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) as $line) {
                $this->line($line);
            }

            return self::SUCCESS;
        }

        // Check if non-interactive
        if (! $this->input->isInteractive()) {
            $this->renderSummary($dto);
            if (empty($dto->packages)) {
                $this->warn('No matching workspace packages found.');
            } else {
                $this->renderTable($dto);
            }

            return self::SUCCESS;
        }

        if (empty($dto->packages)) {
            $this->warn('No matching workspace packages found.');

            return self::SUCCESS;
        }

        // Interactive TUI loop
        while (true) {
            // Re-collect data to reflect any actions taken
            $dto = $this->collector->collect($filterWorkspace, $onlyDirty);
            $this->renderSummary($dto);

            $options = [];
            foreach ($dto->packages as $name => $pkg) {
                $statusMarkers = [];

                if ($pkg['isDirty']) {
                    $statusMarkers[] = "<fg=yellow>dirty (+{$pkg['dirtyFilesCount']})</>";
                } else {
                    $statusMarkers[] = '<fg=green>clean</>';
                }

                if ($pkg['installStatus'] !== 'unlinked') {
                    $statusMarkers[] = "<fg=cyan>{$pkg['installStatus']}</>";
                }

                if ($pkg['auditStatus'] === 'PASSED') {
                    $statusMarkers[] = '<fg=bright-green>AUDIT:PASS</>';
                } elseif ($pkg['auditStatus'] === 'FAILED') {
                    $statusMarkers[] = '<fg=bright-red>AUDIT:FAIL</>';
                }

                $branch = $pkg['gitBranch'] ? " ({$pkg['gitBranch']})" : '';
                $markerStr = implode(' | ', $statusMarkers);

                $label = "{$name}{$branch} [{$markerStr}]";
                $options[$name] = $label;
            }

            $exitKey = '__exit__';
            $options[$exitKey] = '<fg=gray>[Exit Dashboard]</>';

            $selected = select(
                label: 'Select a package to inspect or perform actions:',
                options: $options,
            );

            if ($selected === $exitKey) {
                $this->info('Exited Workspace Mission Control.');
                break;
            }

            $this->handlePackageActions((string) $selected, $dto->packages[$selected]);
        }

        return self::SUCCESS;
    }

    /**
     * Handle actions for a selected package.
     *
     * @param  array<string, mixed>  $pkg
     */
    protected function handlePackageActions(string $packageName, array $pkg): void
    {
        $this->newLine();
        $this->line("<options=bold>Package Details: {$packageName}</>");
        $this->line("  • Path:        {$pkg['packagePath']}");
        $this->line("  • Workspace:   {$pkg['workspace']}".($pkg['isFlat'] ? ' (Flat)' : ' (Nested)'));
        $this->line("  • Installed:   {$pkg['installStatus']}");
        $this->line('  • Git:         '.($pkg['isGitRepo'] ? "branch [{$pkg['gitBranch']}], ".($pkg['isDirty'] ? "dirty ({$pkg['dirtyFilesCount']} files)" : 'clean') : 'No git repository'));
        $this->line("  • Audit:       {$pkg['auditStatus']}".($pkg['auditVersion'] ? " (v{$pkg['auditVersion']})" : ''));
        $this->newLine();

        $actionOptions = [
            'quick_check' => '⚡ Run Quick Check (Composer + Pint)',
            'deep_check' => '🧪 Run Deep Check & Tests (PHPStan + PHPUnit)',
            'isolated_check' => '🛡️ Run Isolated Check (Clean temp environment)',
            'fix_pint' => '🎨 Fix Code Style (Pint --fix)',
            'toggle_link' => $pkg['installStatus'] === 'unlinked'
                ? '🔗 Install into Host (composer require --dev)'
                : '🔌 Uninstall from Host (composer remove)',
            'skills' => '🤖 Materialize Agent Skills',
            'deps' => '📊 View Dependency Tree (DAG)',
            'workflow' => '📜 Generate CI Matrix Workflow',
            'back' => '↩️ Back to Dashboard',
        ];

        $action = select(
            label: "Action for [{$packageName}]:",
            options: $actionOptions,
            default: 'quick_check'
        );

        $this->newLine();

        switch ($action) {
            case 'quick_check':
                $this->call('package:check', ['package' => $packageName, '--quick' => true]);
                break;
            case 'deep_check':
                $this->call('package:check', ['package' => $packageName]);
                break;
            case 'isolated_check':
                $this->call('package:check', ['package' => $packageName, '--isolated' => true]);
                break;
            case 'fix_pint':
                $this->call('package:check', ['package' => $packageName, '--fix' => true, '--only' => 'pint']);
                break;
            case 'toggle_link':
                if ($pkg['installStatus'] === 'unlinked') {
                    $this->call('package:install', ['package' => $packageName, '--dev' => true]);
                } else {
                    $this->call('package:uninstall', ['package' => $packageName]);
                }
                break;
            case 'skills':
                $this->call('package:skills', ['package' => $packageName, '--symlink' => true]);
                break;
            case 'deps':
                $this->call('package:deps', ['package' => $packageName]);
                break;
            case 'workflow':
                $this->call('package:workflow', ['package' => $packageName, '--force' => true]);
                break;
            case 'back':
            default:
                break;
        }

        $this->newLine();
    }

    /**
     * Render the summary metric box.
     */
    protected function renderSummary(WorkspaceDashboardDTO $dto): void
    {
        $this->newLine();
        $this->line('<options=bold;fg=white;bg=blue>  WORKSPACE MISSION CONTROL  </>');
        $this->line(sprintf(
            'Workspaces: <info>%d</info> | Packages: <info>%d</info> | Installed: <info>%d</info> | Dirty: %s | Audit Passed: %s',
            $dto->totalWorkspaces,
            $dto->totalPackages,
            $dto->installedCount,
            $dto->dirtyCount > 0 ? "<fg=yellow>{$dto->dirtyCount}</>" : '<fg=green>0</>',
            $dto->auditPassedCount > 0 ? "<fg=green>{$dto->auditPassedCount}</>" : '<fg=gray>0</>'
        ));
        $this->newLine();
    }

    /**
     * Render non-interactive summary table.
     */
    protected function renderTable(WorkspaceDashboardDTO $dto): void
    {
        $rows = [];
        foreach ($dto->packages as $pkg) {
            $gitCol = $pkg['isGitRepo']
                ? ($pkg['isDirty'] ? "<fg=yellow>{$pkg['gitBranch']}* ({$pkg['dirtyFilesCount']})</>" : "<fg=green>{$pkg['gitBranch']}</>")
                : '<fg=gray>no-git</>';

            $auditCol = match ($pkg['auditStatus']) {
                'PASSED' => '<fg=green>PASSED</>',
                'FAILED' => '<fg=red>FAILED</>',
                default => '<fg=gray>NONE</>',
            };

            $rows[] = [
                "<info>{$pkg['name']}</info>",
                $pkg['workspace'],
                $pkg['installStatus'],
                $gitCol,
                $auditCol,
            ];
        }

        $this->table(['Package', 'Workspace', 'Installed', 'Git Status', 'Audit'], $rows);
    }
}
