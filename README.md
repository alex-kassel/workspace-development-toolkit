# Laravel Workspace Development Toolkit

> **Transform your Laravel application into a Mission Control Center for building, testing, and managing multiple local packages across isolated, git-ready workspaces.**

---

## Table of Contents

1. [Introduction & Philosophy](#introduction--philosophy)
2. [Key Highlights](#key-highlights)
3. [The Two Workspace Paradigms](#the-two-workspace-paradigms)
   - [Multi-Vendor (Nested Structure)](#1-multi-vendor-workspace-nested-structure)
   - [Fixed-Vendor (Flat Structure)](#2-fixed-vendor-workspace-flat-structure)
4. [Quick Start Tutorial (60 Seconds)](#quick-start-tutorial-60-seconds)
5. [Interactive Help & CLI Guide](#interactive-help--cli-guide)
6. [Command Reference](#command-reference)
   - [`workspace:help`](#workspacehelp)
   - [`workspace:add`](#workspaceadd)
   - [`workspace:list`](#workspacelist)
   - [`workspace:default`](#workspacedefault)
   - [`workspace:remove`](#workspaceremove)
   - [`package:make`](#packagemake)
   - [`package:install`](#packageinstall)
   - [`package:uninstall`](#packageuninstall)
   - [`package:delete`](#packagedelete)
7. [Smart Developer Experience (DX)](#smart-developer-experience-dx)
8. [Under the Hood: Architecture & Manifest](#under-the-hood-architecture--manifest)
9. [Real-World Recipes & Patterns](#real-world-recipes--patterns)
10. [Troubleshooting & Domain Exceptions](#troubleshooting--domain-exceptions)

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

## The Two Workspace Paradigms

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
php artisan workspace:add labs --vendor=alex-kassel-labs --default
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
php artisan help workspace:add
```

---

## Command Reference

### `workspace:help`
Displays a comprehensive, colorized interactive cheatsheet, detailing paradigms and practical workflows.

```bash
php artisan workspace:help
```

---

### `workspace:add`
Registers a new workspace directory into `composer.json` (as a path repository), `.gitignore`, and `workspace.json`.

```bash
# Add a multi-vendor nested workspace:
php artisan workspace:add packages

# Add a flat workspace with a fixed vendor:
php artisan workspace:add labs --vendor=alex-kassel-labs

# Add and set as default:
php artisan workspace:add modules --vendor=app-core --default
```

**Options**:
* `--vendor=`: Fixed vendor name for flat 1-level package structure.
* `--default`: Set this workspace as the default workspace for `package:make`.

---

### `workspace:list`
Lists all registered workspaces, their designated vendor, directory structure mode (Flat vs Nested), package count, and member packages.

```bash
php artisan workspace:list
```

**Sample Output**:
```text
+-----------+---------+------------------+-----------+----------------+-------------------------------------------+
| Workspace | Default | Vendor           | Structure | Packages Count | Packages                                  |
+-----------+---------+------------------+-----------+----------------+-------------------------------------------+
| labs      | No      | alex-kassel-labs | Flat      | 2              | ai-assistant                              |
|           |         |                  |           |                | telemetry                                 |
| packages  | Yes     | (none / multi)   | Nested    | 1              | alex-kassel/workspace-development-toolkit |
+-----------+---------+------------------+-----------+----------------+-------------------------------------------+
```

---

### `workspace:default`
Sets the active default workspace. Any subsequent `package:make` call without `--workspace` will target this workspace.

```bash
php artisan workspace:default labs
```

---

### `workspace:remove`
Unregisters a workspace from root `composer.json` and `workspace.json`.

```bash
php artisan workspace:remove labs
```
> [!NOTE]
> Physical files and directories on disk are **never deleted** by `workspace:remove`. An actionable hint will remind you how to remove the folder manually if desired.

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
php artisan workspace:add clients/client-alpha --vendor=alpha-corp
php artisan workspace:add clients/client-beta --vendor=beta-corp

php artisan package:make payment-gateway --workspace=clients/client-alpha
php artisan package:make crm-sync --workspace=clients/client-beta
```

### Pattern B: Modular Monolith / DDD
Organize domain modules cleanly in `modules/`:
```bash
php artisan workspace:add modules --vendor=my-app --default
php artisan package:make billing
php artisan package:make ordering
php artisan package:make inventory
```

### Pattern C: Open-Source Library Incubator
Develop public packages ready for GitHub and Packagist:
```bash
php artisan workspace:add packages --default
php artisan package:make my-handle/laravel-cache-warmer --install --dev
```

---

## Troubleshooting & Domain Exceptions

All exceptions thrown by the toolkit extend `AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException` and include a built-in solution suggestion:

| Exception Class | Cause | Resolution |
| :--- | :--- | :--- |
| `WorkspaceNotFoundException` | Specified workspace path is not registered. | Run `php artisan workspace:add <path>` or check `workspace:list`. |
| `PackageNotFoundException` | Target package was not found in any workspace. | Check spelling or create it with `package:make`. |
| `DefaultWorkspaceNotConfiguredException` | No default workspace is configured. | Run `php artisan workspace:default <path>`. |
| `ComposerProcessException` | Composer command failed or timed out. | Inspect Composer error output; check dependency conflicts. |
| `InvalidJsonException` | Corrupted `composer.json` or `workspace.json`. | Fix syntax errors in the JSON file indicated in the error message. |

---

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
