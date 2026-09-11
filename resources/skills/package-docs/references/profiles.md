# README Profiles Guide

Not every package is identical. While every README MUST respect the 9 Functional Invariants outlined in `policy.md`, the voice, structure, and focal points adapt to the package profile.

---

## Profile 1: `laravel-package` (Standard Laravel Addon)

**Characteristics**: ServiceProvider, Facade, Config file, optionally migrations/models/views.
**Primary Focus**: Seamless framework integration, config defaults, standard Laravel idioms.

### Key Structure:
1. **Hero**: Package Name, 1-line elevator pitch, badges (Packagist, PHP/Laravel matrix, Audit badge).
2. **Why This Exists**: Problem it solves for a Laravel developer.
3. **Requirements**: PHP (e.g. `^8.2`), Laravel (`^11.0|^12.0`).
4. **Installation**: `composer require vendor/package` + auto-discovery note.
5. **Configuration**: `php artisan vendor:publish --tag=package-config`. Table of primary config keys.
6. **Quickstart**: A 30-second controller/service snippet showing the facade or injected service in action.
7. **Usage / Recipes**: Common use cases (middleware, events, Eloquent traits).
8. **Testing**: `composer test`.
9. **License**: MIT.

---

## Profile 2: `cli-tool` / `developer-tooling`

**Characteristics**: Artisan commands, dev-time assistance, audit/linting engines, scaffolding.
**Primary Focus**: Developer ergonomics, command-line arguments/options, console outputs, CI integration.

### Key Structure:
1. **Hero**: Tool Name, concise tagline, badges (`composer require --dev`, license, audit status).
2. **Why This Exists**: Developer workflow pain points solved.
3. **Installation**: Emphasize `composer require --dev vendor/tool`.
4. **Quickstart**: Single command execution showing real terminal output example.
5. **Command Reference**:
   - Signature: `php artisan tool:run [target] [--options]`
   - Parameter & option tables with defaults.
   - Return codes and exit behaviors.
6. **CI/CD Integration**: Example GitHub Actions workflow snippet.
7. **Testing**: `composer test`.

---

## Profile 3: `library` (Pure PHP / Framework Agnostic)

**Characteristics**: Zero Laravel dependency (or illuminate contracts only), pure business/algorithm logic.
**Primary Focus**: High performance, strict types, dependency-free simplicity, interface contracts.

### Key Structure:
1. **Hero**: Library name, badges, minimal dependency statement.
2. **Why This Exists**: Performance, clean architecture, or domain model isolation.
3. **Requirements**: PHP version and required extensions only.
4. **Installation**: `composer require vendor/library`.
5. **Quickstart**: Instantiating the object and invoking public methods with type annotations.
6. **API Reference**: Detailed signatures and domain exceptions.
7. **Testing & Static Analysis**: PHPUnit and PHPStan level instructions.

---

## Profile 4: `workspace-toolkit` / `meta-package`

**Characteristics**: Orchestrates multi-package repositories, local path repos, developer environments.
**Primary Focus**: Workspace concepts, manifest format, workflow commands, multi-package safety.

### Key Structure:
1. **Hero**: Workspace Toolkit branding, architecture role.
2. **Mental Model / Concepts**: Clear explanation of how workspaces, packages, and isolation relate.
3. **Requirements**: Host application prerequisites.
4. **Installation & Setup**: Root setup instructions.
5. **Quickstart / Day-to-Day Workflow**: The 3 commands used daily (`workspace:list`, `pkg:check`, etc.).
6. **Command Reference**: Grouped by domain (`workspace:*`, `pkg:*`).
7. **Directory Layout**: ASCII tree explaining where packages and configs reside.