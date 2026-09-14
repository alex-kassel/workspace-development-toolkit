<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Enums\DiagnosticSeverity;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceNotFoundException;
use Illuminate\Support\Str;
use Laravel\Prompts\Prompt;

class WorkspaceFlattenCommand extends BaseWorkspaceCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workspace:flatten
        {path? : The workspace directory path (e.g. app/Domains/ISS)}
        {vendor? : The default vendor name to assign (e.g. alex-kassel)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Flatten a workspace directory structure into a single-vendor layout';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $path = $this->getRequiredWorkspacePath();
        if ($path === null) {
            return self::FAILURE;
        }

        if (! array_key_exists($path, $this->workspace->all())) {
            return $this->handleWorkspaceException(
                new WorkspaceNotFoundException($path, array_keys($this->workspace->all()))
            );
        }

        $rawVendor = trim((string) $this->argument('vendor'));
        $isInteractive = $this->input->isInteractive() && @stream_isatty(STDIN);

        if ($rawVendor === '') {
            if ($isInteractive && class_exists(Prompt::class)) {
                try {
                    $rawVendor = trim((string) \Laravel\Prompts\text(
                        label: "Enter the default vendor name for workspace [{$path}]:",
                        placeholder: 'e.g. alex-kassel',
                        required: true
                    ));
                } catch (\Throwable) {
                    $rawVendor = '';
                }
            }
        }

        if ($rawVendor === '') {
            $this->dispatchDiagnostic(
                code: 'CMD_ARGUMENT_REQUIRED',
                message: 'Vendor argument is required to flatten a workspace.',
                severity: DiagnosticSeverity::Error,
                remediationSteps: [
                    "php artisan workspace:flatten {$path} alex-kassel",
                ],
                agentGuidance: "Provide the target vendor as the second argument: 'php artisan workspace:flatten <path> <vendor>'."
            );

            return self::FAILURE;
        }

        $cleanVendor = Str::slug($rawVendor);
        if ($cleanVendor === '') {
            $this->error("Invalid vendor [{$rawVendor}]. Vendor may only contain alphanumeric characters.");

            return self::FAILURE;
        }

        try {
            $result = $this->workspace->flatten($path, $cleanVendor);
        } catch (WorkspaceException $e) {
            return $this->handleWorkspaceException($e);
        }

        $this->info("Workspace [{$path}] flattened successfully for default vendor [{$cleanVendor}].");
        $this->line("  • Updated composer.json path repository pattern to: <info>{$path}/*</info>");
        $this->line("  • Workspace manifest saved to: <info>{$path}/workspace.json</info>");

        if (! empty($result['flattened'])) {
            $this->line('  • Flattened packages: '.implode(', ', $result['flattened']));
        }
        if (! empty($result['foreign'])) {
            $this->warn('  • Relocated foreign vendor packages: '.implode(', ', $result['foreign']));
        }

        $this->newLine();
        $this->line('  <comment>Proactive hint:</comment> You can now create packages directly with short names:');
        $this->line("  <info>php artisan package:make my-package --workspace={$path}</info>");

        return self::SUCCESS;
    }
}
