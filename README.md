<h1 align="center">📦 Laravel Workspace Development Toolkit</h1>

<p align="center">
  <strong>Multi-workspace local package development toolkit for Laravel: manage, symlink, and develop isolated packages across git-ready workspaces.</strong>
</p>

<p align="center">
  <a href="#requirements">Requirements</a> •
  <a href="#installation">Installation</a> •
  <a href="#quick-start-tutorial-60-seconds">Quick Start</a> •
  <a href="#command-reference">Commands</a> •
  <a href="#testing">Testing</a> •
  <a href="CHANGELOG.md">Changelog</a>
</p>

<p align="center">
  <a href="AUDIT.json"><img src="https://img.shields.io/badge/Audit-Verified-10b981?logo=shield" alt="Audit Verified"></a>
  <a href="https://packagist.org/packages/alex-kassel/workspace-development-toolkit"><img src="https://img.shields.io/packagist/v/alex-kassel/workspace-development-toolkit?color=f59e0b&logo=packagist&logoColor=white" alt="Latest Version"></a>
  <a href="https://laravel.com"><img src="https://img.shields.io/badge/Laravel-11%20%7C%2012%20%7C%2013-ff2d20?logo=laravel&logoColor=white" alt="Laravel Support"></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/PHP-8.2+-777bb4?logo=php&logoColor=white" alt="PHP Support"></a>
  <a href="phpstan.neon.dist"><img src="https://img.shields.io/badge/PHPStan-Level%208-8b5cf6?logo=php&logoColor=white" alt="PHPStan Level 8"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/License-MIT-blue.svg" alt="License"></a>
</p>

---

## Requirements

* **PHP**: `^8.2` (PHP 8.2, 8.3, or 8.4)
* **Laravel**: `^11.0`, `^12.0`, or `^13.0`
* **Composer**: `^2.2` with path repository support

---

## Installation

Install the package via Composer into your Laravel application (typically as a dev dependency):

```bash
composer require alex-kassel/workspace-development-toolkit --dev
```

The package service provider (`AlexKassel\WorkspaceDevelopmentToolkit\WorkspaceDevelopmentToolkitServiceProvider`) and facade (`Workspace`) are automatically discovered by Laravel.

Optionally, publish the package configuration:

```bash
php artisan vendor:publish --tag=workspace-config
```

### Configuration & Environment Variables

You can configure the toolkit via your published `config/workspace.php` or directly in your host `.env` file. Copy the following block into your `.env`:

```bash
# -----------------------------------------------------------------------------
# Workspace Development Toolkit
# -----------------------------------------------------------------------------
WORKSPACE_REPOSITORY_URL_TEMPLATE=git@github.com:{package}.git
WORKSPACE_PROCESS_TIMEOUT=300
WORKSPACE_SKILLS_PATH=.agents/skills
WORKSPACE_AUTO_PUBLISH_SKILLS=true
WORKSPACE_SCAFFOLD_AGENT_SKILLS=true
WORKSPACE_TRUSTED_ORGANIZATIONS_COMMASEPARATED=
```

---

## Table of Contents

1. [Requirements](#requirements)
2. [Installation](#installation)
3. [Configuration & Environment Variables](#configuration--environment-variables)
4. [Introduction & Philosophy](#introduction--philosophy)
5. [Key Highlights](#key-highlights)
6. [Usage](#usage)
   - [The Two Workspace Paradigms](#the-two-workspace-paradigms)
   - [Quick Start Tutorial (60 Seconds)](#quick-start-tutorial-60-seconds)
   - [Interactive Help & CLI Guide](#interactive-help--cli-guide)
7. [Command Reference](#command-reference)
   - [`workspace:help`](#workspacehelp)
   - [`workspace:register`](#workspaceregister)
   - [`workspace:unregister`](#workspaceunregister)
   - [`workspace:flatten`](#workspaceflatten)
   - [`workspace:unflatten`](#workspaceunflatten)
   - [`workspace:list`](#workspacelist)
   - [`workspace:default`](#workspacedefault)
   - [`php workspace restore`](#php-workspace-restore-standalone-cli-runner)
   - [`workspace:sync`](#workspacesync)
   - [`package:make`](#packagemake)
   - [`package:clone`](#packageclone)
   - [`package:alias`](#packagealias)
   - [`package:install`](#packageinstall)
   - [`package:uninstall`](#packageuninstall)
   - [`package:delete`](#packagedelete)
   - [`package:skills`](#packageskills)
   - [`package:check`](#packagecheck)
   - [`package:deps`](#packagedeps)
   - [`package:workflow`](#packageworkflow)
   - [`package:readme`](#packagereadme)
   - [`package:release-check`](#packagerelease-check)
8. [Smart Developer Experience (DX)](#smart-developer-experience-dx)
9. [Under the Hood: Architecture & Manifest](#under-the-hood-architecture--manifest)
10. [Real-World Recipes & Patterns](#real-world-recipes--patterns)
11. [Troubleshooting & Domain Exceptions](#troubleshooting--domain-exceptions)
12. [Testing](#testing)
13. [License](#license)

---

## Introduction & Philosophy

Modern Laravel engineering often demands managing dozens of distinct codebases simultaneously:
* Custom domain packages (DDD / modular monoliths).
* Client-specific micro-applications and customizations.
* Open-source libraries slated for Packagist publication.

Traditional approaches force developers into agonizing compromises: awkward `repositories` configurations in root `composer.json`, manual `.gitignore` edits, git submodules, or constantly jumping between disconnected project windows.

**Workspace Development Toolkit** bridges this gap by making your host Laravel application the centralized staging ground and mission control center:
* Every package lives in its own directory with its own independent `composer.json` and can be initialized as a separate Git repository.
* Workspaces are registered automatically as Composer `path` repositories with instant symlinking.
* Zero custom logic is placed in the host application — the toolkit remains fully autonomous.

---

## Key Highlights

* 🚀 **Zero-Friction Scaffolding**: Create production-ready Laravel packages (with `ServiceProvider`, PSR-4 autoloading, and manifests) with a single artisan command.
* 📦 **Dual Workspace Architecture**:
  * **Nested (Multi-Vendor)**: `packages/{vendor}/{package}/` for public libraries and diverse vendors.
  * **Flat (Fixed-Vendor)**: `labs/{package}/` or `clients/{package}/` without redundant vendor subdirectories.
* 🔗 **Automated Symlinking**: Optional instant installation into `require` or `require-dev` on package creation.
* 🛡️ **Defensive Developer Experience**:
  * Auto-sanitizes namespaces and paths (e.g. `Acme\Billing!` -> `acme/billing`).
  * Forgiving input parser accepting Windows backslashes (`\`) or standard slashes (`/`).
  * Actionable `<comment>How to fix:</comment>` blocks on every error.
  * Context-aware `<comment>Hint:</comment>` blocks suggesting your next logical command.
* 📋 **Interactive CLI Manual**: Built-in `workspace:help` command providing real-time cheatsheets and workflow guidance.

---

## Usage

The toolkit supports both multi-vendor libraries and dedicated client or internal modules through flexible workspace paradigms.

### The Two Workspace Paradigms

### 1. Multi-Vendor Workspace (Nested Structure)
* **Configuration**: `"vendor": null`
* **Path Pattern on Disk**: `{workspace}/*/*`
* **Directory Structure**:
  ```text
  packages/
  ├── alex-kassel/
  │   └── toolkit/
  └── spatie/
      └── custom-backup/
  ```
* **Best Suited For**: Public open-source packages, vendor forks, or mixed teams.
* **Creating a Package**:
  ```bash
  php artisan package:make my-vendor/my-package --workspace=packages
  ```

---

### 2. Fixed-Vendor Workspace (Flat Structure)
* **Configuration**: `"vendor": "my-vendor"`
* **Path Pattern on Disk**: `{workspace}/*`
* **Directory Structure**:
  ```text
  labs/
  ├── billing/          # composer name: "my-vendor/billing"
  ├── crm/              # composer name: "my-vendor/crm"
  └── notifications/    # composer name: "my-vendor/notifications"
  ```
* **Best Suited For**: Internal company modules, domain services, or client workspaces where repeating the vendor name in the filesystem is redundant.
* **Creating a Package**:
  ```bash
  php artisan package:make billing --workspace=labs
  ```
  *(Composer package name automatically generated as `my-vendor/billing`, namespace: `MyVendor\Billing`)*.

---

## Quick Start Tutorial (60 Seconds)

### Step 1: Create a Flat Workspace with a Fixed Vendor
```bash
php artisan workspace:register labs --vendor=alex-kassel-labs --default
```
* Adds `labs/*` path repository to root `composer.json`.
* Adds `/labs` to `.gitignore`.
* Marks `labs` as default workspace.

### Step 2: Create and Install a Local Module
```bash
php artisan package:make ai-assistant --install --dev
```
* Scaffolds `labs/ai-assistant/src/AiAssistantServiceProvider.php`.
* Sets package manifest to `alex-kassel-labs/ai-assistant`.
* Immediately runs `composer require alex-kassel-labs/ai-assistant --dev`.
* Everything is active in your Laravel app!

### Step 3: Inspect Workspaces
```bash
php artisan workspace:list
```

---

## Interactive Help & CLI Guide

Run `php artisan workspace:help` at any time to view the interactive color-coded guide:

```bash
php artisan workspace:help
```

You can also view standard Laravel help for any specific command:
```bash
php artisan help package:make
php artisan help workspace:register
```

---

## Command Reference

### `workspace:help`
Displays a comprehensive, colorized interactive cheatsheet, detailing paradigms and practical workflows.

```bash
php artisan workspace:help
```

---

### `workspace:register`
Registers a new workspace directory into `composer.json` (as a path repository), `.gitignore`, and `workspace.json`.

```bash
# Register a multi-vendor nested workspace:
php artisan workspace:register packages

# Register a flat workspace with a default vendor:
php artisan workspace:register labs --vendor=alex-kassel-labs

# Register and set as default:
php artisan workspace:register modules --vendor=app-core --default
```

**Options**:
* `--vendor=`: Default vendor name for flat 1-level package structure.
* `--default`: Set this workspace as the default workspace for `package:make`.

> [!NOTE]
> When registering a workspace that already contains subdirectories, the toolkit inspects disk contents and displays a diagnostic warning if unversioned directories are detected (preventing code loss from `.gitignore` exclusions).

---

### `workspace:unregister`
Unregisters a workspace from root `composer.json` and `workspace.json`.

```bash
# Unregister workspace repository (preserves files on disk):
php artisan workspace:unregister labs

# Detach and uninstall any active packages required in composer.json:
php artisan workspace:unregister labs --detach

# Permanently purge workspace files and directory from disk:
php artisan workspace:unregister labs --purge --force
```

> [!NOTE]
> By default, physical files and directories on disk are **never deleted** by `workspace:unregister`. An actionable hint will remind you how to remove the folder manually if desired.

---

### `workspace:flatten`
Converts an existing workspace into a flat (single-depth) layout and sets its default vendor.

```bash
php artisan workspace:flatten app/Domains/ISS alex-kassel
```

* Automatically relocates any existing packages from nested `<vendor>/<pkg>` to single-depth `<pkg>`.
* Preserves and relocates any foreign-vendor packages cleanly.
* Updates `composer.json` path repository URL to `path/*` (single-depth).
* Persists `<workspace>/workspace.json` manifest.

---

### `workspace:unflatten`
Reverts a flat workspace back into a multi-vendor nested layout (`path/*/*`).

```bash
php artisan workspace:unflatten app/Domains/ISS
```

---

### `workspace:sync`
Synchronizes root `composer.json` path repository definitions and scripts with all registered workspaces.

```bash
# Perform idempotent synchronization:
php artisan workspace:sync

# Inspect what would be synchronized without writing to disk:
php artisan workspace:sync --dry-run
```

> [!TIP]
> The synchronization is **strictly idempotent**: if root `composer.json` already contains the correct repository paths, it will **not touch or reformat the file** on disk, completely eliminating ghost git diffs in team environments.

---

### `package:make`
Scaffolds a new minimal Laravel package with a ServiceProvider, `composer.json`, and PSR-4 autoloading.

```bash
# In a multi-vendor workspace (requires vendor/package):
php artisan package:make my-vendor/my-package --workspace=packages

# In a fixed-vendor workspace (single-word allowed):
php artisan package:make analytics --workspace=labs

# Create and link immediately as dev-dependency:
php artisan package:make billing --workspace=labs --install --dev
```

**Options**:
* `--workspace=`: The target workspace (defaults to current default workspace).
* `--install`: Immediately trigger `composer require` for the package upon creation.
* `--dev`: Install into `require-dev` instead of `require` (used in conjunction with `--install`).

---

### `package:clone`
Clones a package repository from Git/GitHub into a target workspace, registers it in `workspace.json`, and optionally symlinks it via Composer.

```bash
# Clone via shorthand (resolved using repository_template, e.g. GitHub SSH/HTTPS):
php artisan package:clone spatie/laravel-ray --workspace=packages

# Clone via full SSH or HTTPS URL:
php artisan package:clone git@github.com:vendor/package.git

# Clone and immediately symlink as development dependency:
php artisan package:clone vendor/package --install --dev

# Recursively clone internal dependencies from trusted organizations:
php artisan package:clone vendor/package --recursive

# Clone the toolkit itself into a local workspace for active contribution:
php artisan package:clone --self
```

---

### `package:alias`
Assigns a clean, customized directory alias to a package residing in a flat (fixed-vendor) workspace without altering its Composer package name.

```bash
# Assign a directory alias using arguments:
php artisan package:alias billing MyBilling

# Or using the --as option:
php artisan package:alias billing --as=MyBilling
```

**Options**:
* `--as=`: Alternate option to specify the new directory alias.
* `--alias=`: Synonym for `--as`.

> [!NOTE]
> Directory aliasing renames the folder on disk, adjusts `workspace.json`, and triggers `composer dump-autoload` automatically to refresh PSR-4 autoload mappings.

---

### `package:install`
Links an existing workspace package into the root application using Composer.

```bash
# By canonical name:
php artisan package:install alex-kassel-labs/ai-assistant --dev

# Or by short name (if belonging to a fixed-vendor workspace):
php artisan package:install ai-assistant --dev
```

---

### `package:uninstall`
Removes a package from root `composer.json` via `composer remove`.

```bash
# By canonical name:
php artisan package:uninstall alex-kassel-labs/ai-assistant

# Or by short name:
php artisan package:uninstall ai-assistant
```
> [!TIP]
> `package:uninstall` automatically detects whether the package is located in `require` or `require-dev` and applies `--dev` automatically.

---

### `package:delete`
Permanently uninstalls the package from root Composer (if installed) and deletes the package directory from disk.

```bash
# Interactive confirmation:
php artisan package:delete ai-assistant

# Force deletion without prompt:
php artisan package:delete ai-assistant --force
```
> [!NOTE]
> `package:delete` includes built-in Git safety checks (via `GitInspector`) preventing accidental removal of dirty trees, unpushed commits, or stashed changes unless `--force` is provided.

---

### `package:skills`
Discovers and materializes AI agent skills (`SKILL.md`) from a package's `resources/skills` into the host application's `.agents/skills/` directory (or configured skills destination).

```bash
# Materialize published skills into the project (copy mode):
php artisan package:skills my-package

# Symlink skills for live development (changes sync immediately):
php artisan package:skills my-package --symlink

# Overwrite skills if already present:
php artisan package:skills my-package --force

# Remove all installed skills for this package from the project:
php artisan package:skills my-package --remove
```

**Options**:
* `--symlink`: Create symlinks instead of copying files (ideal for live development within local workspaces).
* `--force`: Force overwrite existing skills even if already present.
* `--remove`: Remove all materialized skills associated with this package from the project.

> [!TIP]
> The command strictly honors skill publishing lifecycle rules: skills in draft status (where `status != 'published'` in the `SKILL.md` frontmatter) are skipped automatically unless `--force` is specified.

---

### `package:check`
Runs automated quality gates across local packages (Composer validation, Pint, PHPStan, PHPUnit/Pest).

```bash
# Check a single package (deep tier by default: Composer + Pint + PHPStan + Tests):
php artisan package:check my-package

# Run quick tier checks only (Composer + Pint in seconds):
php artisan package:check my-package --quick

# Run specific checks only:
php artisan package:check my-package --only=pint,phpstan

# Automatically fix code style with Pint:
php artisan package:check my-package --fix

# Run tests in an isolated temporary Laravel environment:
php artisan package:check my-package --isolated

# Run isolated tests linking unpublished local sibling packages:
php artisan package:check my-package --isolated --with-workspace-deps

# Run checks on my-package and automatically run regression tests for all dependent packages:
php artisan package:check my-package --affected

# Check all registered packages across workspaces:
php artisan package:check --all

# Check all registered packages with quick checks:
php artisan package:check --all --quick
```

**Options**:
* `--quick`: Run quick tier checks only (`composer` validation and `pint` style check). Default is deep tier (`composer`, `pint`, `phpstan`, `tests`).
* `--fix`: Automatically format and fix code style issues using Pint.
* `--only=`: Comma-separated list of checks to run (`composer`, `pint`, `phpstan`, `tests`, `isolated`).
* `--isolated`: Install and test an independent temporary copy of the package in isolation with Composer cache optimization (`--prefer-offline`).
* `--with-workspace-deps`: Allow isolated checks to resolve and link unpublished sibling packages in your workspace using local path repositories. If omitted when sibling dependencies exist, the check halts with an explicit, actionable error.
* `--affected`: Traverse the workspace dependency graph (DAG) and execute regression test suites for all packages that depend on this package.
* `--all`: Verify all packages across all registered workspaces.

---

### `package:deps`
Inspects and visualizes the dependency graph (DAG) for a package, showing direct and transitive dependencies as well as downstream dependents. It also detects circular dependency loops.

```bash
# Display an ASCII tree of dependencies and dependents:
php artisan package:deps my-package

# Output a Mermaid diagram (pasteable into Markdown or GitHub):
php artisan package:deps my-package --mermaid

# Output raw JSON for agent or CI pipeline consumption:
php artisan package:deps my-package --json
```

**Options**:
* `--mermaid`: Render a Mermaid graph definition (`flowchart TD`).
* `--json`: Output full graph data (dependencies, dependents, cycle warnings) as JSON.

---

### `package:workflow`
Scaffolds or regenerates a GitHub Actions CI test matrix workflow (`.github/workflows/run-tests.yml`) testing the package across PHP versions (8.2, 8.3, 8.4) and Laravel versions (11.*, 12.*, 13.*).

```bash
# Generate GitHub Actions matrix workflow for a package:
php artisan package:workflow my-package

# Overwrite existing workflow file:
php artisan package:workflow my-package --force
```

**Options**:
* `--force`: Overwrite existing `.github/workflows/run-tests.yml` if it already exists.

---

### `package:readme`
Validates that a package's `README.md` adheres to compliance standards (required sections, heading, absence of placeholder tokens).

```bash
php artisan package:readme my-package
```

---

### `package:release-check`
Runs pre-flight verification before releasing or tagging a package (checks git cleanliness, `.gitattributes` export-ignore, code quality, and README compliance).

```bash
# Full release pre-flight (includes isolated environment test):
php artisan package:release-check my-package

# Fast release pre-flight:
php artisan package:release-check my-package --fast
```

---

## Smart Developer Experience (DX)

### 1. Robust Input Sanitization
The toolkit forgives unconventional inputs:
* **Pasted Class Names / Namespaces**:
  `php artisan package:make "AcmeStudio\SuperWidget"` -> safely converted to `acmestudio/superwidget`.
* **Capital Letters & Symbols**:
  `php artisan package:make "MyVendor/Special_Package!"` -> normalized to `myvendor/special-package`.
* **Slashes**:
  Supports standard forward slashes (`/`), Windows backslashes (`\`), and repeated slashes (`//`).

### 2. Actionable `<comment>How to fix:</comment>` Guidance
Commands never fail silently with obscure system errors. Whenever validation fails, the terminal outputs clear instructions and exact copy-pasteable commands:

```text
Invalid package name [billing]. Workspace [packages] requires a vendor prefix in 'vendor/package' format.
  How to fix: Specify both vendor and package name:
  php artisan package:make my-vendor/billing --workspace=packages
```

### 3. Contextual Next-Step Hints
After successful operations, commands guide you on what to do next:

```text
Package [acme/billing] created successfully in [packages/acme/billing].

  Hint: To link this package into your application via Composer, run:
  php artisan package:install acme/billing
  Or as a dev-dependency: php artisan package:install acme/billing --dev
```

---

## Under the Hood: Architecture & Manifest

### Manifest Format (`workspace.json`)
The central registry is stored at the root of your Laravel project:

```json
{
    "default": "labs",
    "workspaces": {
        "labs": {
            "vendor": "alex-kassel-labs",
            "packages": [
                "ai-assistant",
                "telemetry"
            ]
        },
        "packages": {
            "vendor": null,
            "packages": [
                "alex-kassel/workspace-development-toolkit"
            ]
        }
    }
}
```

### Path Repositories in `composer.json`
* For `packages` (`vendor: null`):
  ```json
  {
      "name": "workspace-packages",
      "type": "path",
      "url": "packages/*/*"
  }
  ```
* For `labs` (`vendor: "alex-kassel-labs"`):
  ```json
  {
      "name": "workspace-labs",
      "type": "path",
      "url": "labs/*"
  }
  ```

---

## Real-World Recipes & Patterns

### Pattern A: Agency & Multi-Client Management
Create separate workspaces for each client project:
```bash
php artisan workspace:register clients/client-alpha --vendor=alpha-corp
php artisan workspace:register clients/client-beta --vendor=beta-corp

php artisan package:make payment-gateway --workspace=clients/client-alpha
php artisan package:make crm-sync --workspace=clients/client-beta
```

### Pattern B: Modular Monolith / DDD
Organize domain modules cleanly in `modules/`:
```bash
php artisan workspace:register modules --vendor=my-app --default
php artisan package:make billing
php artisan package:make ordering
php artisan package:make inventory
```

### Pattern C: Open-Source Library Incubator
Develop public packages ready for GitHub and Packagist:
```bash
php artisan workspace:register packages --default
php artisan package:make my-handle/laravel-cache-warmer --install --dev
```

---

## Troubleshooting & Domain Exceptions

All exceptions thrown by the toolkit extend `AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException` and include a built-in solution suggestion:

| Exception Class | Cause | Resolution |
| :--- | :--- | :--- |
| `WorkspaceNotFoundException` | Specified workspace path is not registered. | Run `php artisan workspace:register <path>` or check `workspace:list`. |
| `PackageNotFoundException` | Target package was not found in any workspace. | Check spelling or create it with `package:make`. |
| `DefaultWorkspaceNotConfiguredException` | No default workspace is configured. | Run `php artisan workspace:default <path>`. |
| `ComposerProcessException` | Composer command failed or timed out. | Inspect Composer error output; check dependency conflicts. |
| `InvalidJsonException` | Corrupted `composer.json` or `workspace.json`. | Fix syntax errors in the JSON file indicated in the error message. |

---

## Testing

The package includes a comprehensive PHPUnit test suite covering workspace manifest handling, package lifecycle, git safety, skill installation, and command interactions:

```bash
# Run tests from the host Laravel application:
php artisan test -c packages/alex-kassel/workspace-development-toolkit/phpunit.xml.dist --compact

# Or run PHPUnit directly:
vendor/bin/phpunit -c packages/alex-kassel/workspace-development-toolkit/phpunit.xml.dist
```

---

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
