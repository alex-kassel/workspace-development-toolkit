# Unified Console UX (`InteractsWithConsoleUx`)

The `InteractsWithConsoleUx` trait provides an ecosystem-wide standard for Artisan console commands across all packages. It solves a fundamental dichotomy in modern CLI development:

1. **For Human Developers (Terminal Interactive Mode):** Renders rich, polished, autocompleted prompts powered by `Laravel\Prompts` (`select`, `text`, `confirm`).
2. **For AI Agents and CI Pipelines (Non-Interactive / Headless Mode):** Fails fast with structured, deterministic diagnostics, explicit remediation steps, valid options, and canonical command usage examples, preventing infinite hangs or cryptic errors.

---

## Architecture Overview

```
                          ┌───────────────────────────┐
                          │     Artisan Command       │
                          │ use InteractsWithConsoleUx │
                          └─────────────┬─────────────┘
                                        │
                         isInteractiveEnvironment()?
                                ┌───────┴───────┐
                                │               │
                              [Yes]           [No]
                                ▼               ▼
                      Human Terminal UX    Agent / CI UX
                      (Laravel Prompts)  (Structured Guidance)
                                │               │
                        • promptSelect()  • failWithGuidance()
                        • promptText()    • ConsoleUxGuidance DTO
                        • promptConfirm() • JSON Output Support
```

### Components

| Class | Namespace | Purpose |
| :--- | :--- | :--- |
| **`InteractsWithConsoleUx`** | `AlexKassel\WorkspaceDevelopmentToolkit\Commands\Concerns` | Main reusable trait for `Illuminate\Console\Command`. |
| **`ConsoleUxGuidance`** | `AlexKassel\WorkspaceDevelopmentToolkit\DTOs` | Pure `final readonly` DTO encapsulating error messages, missing arguments, available options, and AI agent instructions. |

---

## Quick Start & Integration

To equip any console command with unified UX, import the trait into your command:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Commands\Concerns\InteractsWithConsoleUx;
use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\ConsoleUxGuidance;
use Illuminate\Console\Command;

class ScaffoldManifestCommand extends Command
{
    use InteractsWithConsoleUx;

    protected $signature = 'manifest:init
                            {name? : Manifest alias or path}
                            {--force : Overwrite existing file}
                            {--json : Output machine-readable JSON}';

    protected $description = 'Initialize and scaffold a manifest file';

    public function handle(): int
    {
        $name = $this->argument('name');
        $availableAliases = ['workspace', 'plugins', 'services'];

        // 1. Resolve missing input dynamically
        if (! is_string($name) || trim($name) === '') {
            if ($this->isInteractiveEnvironment()) {
                $name = $this->promptSelect(
                    label: 'Select a manifest alias to initialize:',
                    options: $availableAliases,
                );
            } else {
                return $this->failWithGuidance(
                    guidance: 'No manifest alias specified for initialization.',
                    argument: 'name',
                    availableOptions: $availableAliases,
                    usageExample: 'php artisan manifest:init workspace [--force]',
                    agentInstructions: 'Inspect available manifests via manifest:status before calling manifest:init.'
                );
            }
        }

        // 2. Perform operation
        $result = ['status' => 'initialized', 'manifest' => $name];

        // 3. Support JSON output for machine consumers
        if ($this->wantsJsonOutput()) {
            return $this->renderJsonResult($result);
        }

        $this->info("Manifest [{$name}] successfully initialized.");

        return self::SUCCESS;
    }
}
```

---

## Feature Reference

### 1. Interactive Environment Detection (`isInteractiveEnvironment`)

```php
public function isInteractiveEnvironment(): bool
```

Accurately distinguishes between a human sitting at a TTY terminal and automated environments. Returns `false` if:
- `--no-interaction` flag is present.
- Process is running within unit/feature tests (e.g. `PHPUnit`, `Orchestra Testbench`).
- STDIN is redirected or piped.

---

### 2. Interactive Prompts (`promptSelect`, `promptText`, `promptConfirm`)

These methods automatically use `Laravel\Prompts` when available, falling back seamlessly to standard Symfony console methods when Prompts are unavailable:

#### Choice Selection (`promptSelect`)
```php
$tier = $this->promptSelect(
    label: 'Select license tier:',
    options: ['free' => 'Free Community', 'pro' => 'Commercial Pro'],
    default: 'pro'
);
```

#### Text Input (`promptText`)
```php
$path = $this->promptText(
    label: 'Enter package name:',
    placeholder: 'vendor/package',
    required: true
);
```

#### Confirmation (`promptConfirm`)
```php
$confirmed = $this->promptConfirm('Do you want to run migrations now?', default: true);
```

---

### 3. Structured Non-Interactive Guidance (`failWithGuidance`)

When an automated process (such as an AI coding agent or CI script) runs a command without required arguments, it must not hang on prompt inputs, nor should it print ambiguous single-line errors.

`failWithGuidance` outputs a standardized, high-visibility diagnostic block:

```php
return $this->failWithGuidance(new ConsoleUxGuidance(
    message: 'Package alias is required to register workspace symlink.',
    argument: 'alias',
    availableOptions: ['ui', 'billing', 'core'],
    usageExample: 'php artisan workspace:register billing packages/acme-billing',
    remediationSteps: [
        'Run `php artisan workspace:list` to view unlinked packages.',
        'Provide both <alias> and <target-path> as positional arguments.',
    ],
    agentInstructions: 'Extract valid aliases from workspace.json or use `workspace:list --json`.'
));
```

#### Console Output Format:

```text
✘ Error: Package alias is required to register workspace symlink.
  Missing Required Argument: alias
  Available Options:
    • ui
    • billing
    • core
  Usage Example: php artisan workspace:register billing packages/acme-billing
  Remediation Steps:
    1. Run `php artisan workspace:list` to view unlinked packages.
    2. Provide both <alias> and <target-path> as positional arguments.
  AI Agent Guidance: Extract valid aliases from workspace.json or use `workspace:list --json`.
```

---

### 4. Machine-Readable JSON Output (`wantsJsonOutput`, `renderJsonResult`)

For commands that report status, validation, or metrics:

```php
if ($this->wantsJsonOutput()) {
    return $this->renderJsonResult([
        'valid' => true,
        'manifests' => $reports,
    ]);
}
```

- Formats output with `JSON_PRETTY_PRINT`, `JSON_UNESCAPED_SLASHES`, and `JSON_UNESCAPED_UNICODE`.
- Returns `Command::SUCCESS` (0).

---

## Guidelines for AI Coding Agents

When interacting with console commands equipped with `InteractsWithConsoleUx`:
1. **Always pass `--no-interaction`** during autonomous execution to trigger structured diagnostics rather than prompting.
2. **On failure (Exit Code 1):** Parse the `Missing Required Argument`, `Available Options`, and `Usage Example` fields from the output.
3. **Formulate immediate corrective commands** using the provided canonical syntax without needing to inspect source code.
