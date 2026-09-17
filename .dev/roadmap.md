# Workspace Ecosystem Evolution & Refactoring Roadmap

This document provides a detailed architectural roadmap for transforming `alex-kassel/workspace-development-toolkit` and `alex-kassel/workspace-manifest` into production-ready, release-certified Open Source packages.

Each item clearly identifies the **Problem Detected** during architectural review, followed by the **Step-by-Step Solution**.

---

## Phase 1: Workspace Manifest Domain Hardening & Packagist Readiness

Goal: Eliminate domain gaps, CQS violations, test isolation flaws, and path resolution bottlenecks in `alex-kassel/workspace-manifest`.

### 1.1. Dynamic Schema Resolution
* **Problem Detected**: 
  `WorkspaceSchema::DEFAULT_SCHEMA_PATH` is hardcoded to `./packages/alex-kassel/workspace-manifest/resources/schema.json`. When installed via Composer into another project, this path points to a non-existent directory, breaking IDE JSON schema validation and autocomplete for consumers.
* **Step-by-Step Solution**:
  - [ ] Add runtime path detection in `WorkspaceSchema`: check for local monorepo path first (`./packages/...`), then Composer vendor path (`./vendor/alex-kassel/workspace-manifest/resources/schema.json`).
  - [ ] Provide fallback to canonical raw GitHub URL (`https://raw.githubusercontent.com/alex-kassel/workspace-manifest/main/resources/schema.json`).
  - [ ] Update `WorkspaceManifest::init()` and `WorkspaceSchema::defaults()` to use the resolved dynamic schema reference.

### 1.2. CQS Symmetry (DTO Ingestion on Write Operations)
* **Problem Detected**: 
  While reading yields rich `PackageDefinition` and `WorkspaceDefinition` DTOs (`getPackage()`, `getWorkspaceDefinitions()`), writing still requires unwrapping objects into a 5-argument primitive method (`addPackage($ws, $name, $alias, $url, $skills)`), violating Command-Query Separation (CQS) symmetry.
* **Step-by-Step Solution**:
  - [ ] Implement `savePackage(PackageDefinition $package): self` accepting a typed DTO directly.
  - [ ] Implement `saveWorkspace(WorkspaceDefinition $workspace): self` for complete workspace state persistence.
  - [ ] Add immutable fluent mutation helpers on `PackageDefinition` (`withAlias(?string $alias)`, `withSkills(array $skills)`, `withUrl(?string $url)`).
  - [ ] Refactor existing `addPackage()` to internally construct and delegate to `savePackage()`.

### 1.3. Global Multi-Workspace Conflict Prevention (Rule F-03)
* **Problem Detected**: 
  Conflict prevention in `WorkspaceManifest::addPackage()` only scans the *current* workspace. In multi-workspace setups (e.g., `packages` and `modules`), an alias or package name in `modules` can collide with one in `packages`, causing directory collision issues at the application root level.
* **Step-by-Step Solution**:
  - [ ] Expand collision checking in `WorkspaceManifest`: inspect package names and aliases across **all** workspaces, not just the target workspace.
  - [ ] Throw `PackageConflictException` indicating which workspace already owns the conflicting name or alias.
  - [ ] Add unit tests verifying cross-workspace collision rejection.

### 1.4. In-Memory Indexing for O(1) Package Lookup
* **Problem Detected**: 
  `getPackage()`, `findPackageWorkspace()`, and `hasPackage()` perform a linear `O(N)` nested loop over all workspaces and packages on every call. In large monorepos (50+ packages), batch CLI commands (such as `workspace:status`, `package:deps`, `workspace:sync`) incur quadratic scanning overhead.
* **Step-by-Step Solution**:
  - [ ] Add an in-memory lookup index `protected ?array $packageIndex` in `WorkspaceManifest`.
  - [ ] Index entries by exact name, canonical vendor name (`vendor/pkg`), and alias on first load.
  - [ ] Invalidate/rebuild index automatically on file reload or write mutations (`mutate`).
  - [ ] Make `getPackage()` and `hasPackage()` resolve in `O(1)` time complexity via index.

### 1.5. Elimination of Code Duplication between `bin/workspace` and Domain DTOs
* **Problem Detected**: 
  The standalone runner `bin/workspace` duplicates path concatenation and canonical name logic (`$vendor !== null ? ...`) that already exists in `PackageDefinition::canonicalName()` and `effectiveDirectory()`.
* **Step-by-Step Solution**:
  - [ ] Document the intentional zero-dependency boundary in `bin/workspace` (it cannot import Composer classes).
  - [ ] Add an automated parity test comparing `bin/workspace` resolution results against `PackageDefinition` methods across multiple workspace archetype fixtures.

### 1.6. Autonomous Package Test Suite (Packagist Isolation)
* **Problem Detected**: 
  `packages/alex-kassel/workspace-manifest/tests/TestCase.php` inherits from `Tests\TestCase` (the host Laravel application's test case). When cloned as an independent repository, running `composer test` fails because `Tests\TestCase` does not exist outside the host.
* **Step-by-Step Solution**:
  - [ ] Replace `Tests\TestCase` inheritance in `tests/TestCase.php` with direct `Orchestra\Testbench\TestCase` inheritance.
  - [ ] Ensure `packages/alex-kassel/workspace-manifest` passes `composer test` when executed completely in standalone isolation.

---

## Phase 2: Event-Driven Synergy & Cache Elimination

Goal: Leverage `ManifestEngine` and `WorkspaceManifest` domain events to eliminate manual, fragile cache management in `WorkspaceDevelopmentToolkit`.

### 2.1. Domain Event Dispatching in `WorkspaceManifest`
* **Problem Detected**: 
  `WorkspaceManifest` performs mutations silently without firing domain events, forcing external consumers (`WorkspaceDevelopmentToolkit`) to manually guess when to invalidate caches.
* **Step-by-Step Solution**:
  - [ ] Create domain events in `AlexKassel\WorkspaceManifest\Events`:
    - `WorkspaceRegistered(string $workspace, ?string $vendor)`
    - `WorkspaceUnregistered(string $workspace)`
    - `PackageRecorded(PackageDefinition $package)`
    - `PackageRemoved(string $workspace, string $packageName)`
  - [ ] Dispatch these events via `ManifestEngine`'s event dispatcher on successful atomic mutations.

### 2.2. Reactive Cache Invalidation in `WorkspaceDevelopmentToolkit`
* **Problem Detected**: 
  `WorkspaceManager`, `PackageResolver`, and `ManifestRepository` in Toolkit have explicit `$this->clearCache()` calls scattered across dozen of methods, which is brittle and error-prone when adding new actions.
* **Step-by-Step Solution**:
  - [ ] Register event listeners in `WorkspaceDevelopmentToolkitServiceProvider` listening to `WorkspaceManifest` domain events.
  - [ ] Automatically invalidate `PackageResolver` and `WorkspaceManager` caches on event arrival.
  - [ ] Remove manual `$this->clearCache()` clutter from Toolkit service methods.

### 2.3. Native `ManifestDto` Contract Implementation
* **Problem Detected**: 
  `WorkspaceDefinition` and `PackageDefinition` are mapped manually via custom loops in `getWorkspaceDefinitions()`, ignoring `ManifestEngine`'s built-in `toDto()` and `saveDto()` capabilities.
* **Step-by-Step Solution**:
  - [ ] Implement `AlexKassel\ManifestEngine\Contracts\ManifestDto` on `WorkspaceDefinition`.
  - [ ] Use `Manifest::toDto()` and `Manifest::saveDto()` for declarative document-level hydration.

---

## Phase 3: Toolkit Core Simplification & Legacy Purge

Goal: Fully transition `WorkspaceDevelopmentToolkit` to `WorkspaceManifest`, eliminating legacy duplicate layers.

### 3.1. Inline & Deprecate Intermediate `ManifestRepository`
* **Problem Detected**: 
  `AlexKassel\WorkspaceDevelopmentToolkit\Services\ManifestRepository` now mostly acts as a pass-through proxy to `WorkspaceManifest`, creating an unnecessary layer of indirection.
* **Step-by-Step Solution**:
  - [ ] Extract host-specific recovery logic (`heal()` from root `composer.json`) into a dedicated Action: `AlexKassel\WorkspaceDevelopmentToolkit\Actions\ReconstructWorkspaceManifestAction`.
  - [ ] Replace `ManifestRepository` injection with direct `WorkspaceManifest` injection in `WorkspaceManager` and console commands.
  - [ ] Aggressively eliminate dead proxy methods per active pre-1.0 development guidelines.

### 3.2. Single Source of Truth for Path Calculation in Toolkit
* **Problem Detected**: 
  `PackageResolver` still contains custom path resolution logic that occasionally recalculates directory names instead of relying on `PackageDefinition`.
* **Step-by-Step Solution**:
  - [ ] Refactor `PackageResolver` to use `PackageDefinition::effectiveDirectory()` and `canonicalName()` everywhere.
  - [ ] Guarantee 100% path calculation consistency across CLI commands, symlink generators, and Composer sync operations.

---

## Phase 4: Full Regression & Certification

Goal: Verify resilience across all layers, maintain code quality standards, and finalize release documentation.

### 4.1. Automated Verification Matrix
- [ ] Run test suite for `alex-kassel/manifest-engine`: `vendor/bin/phpunit packages/alex-kassel/manifest-engine/tests`.
- [ ] Run test suite for `alex-kassel/workspace-manifest`: `vendor/bin/phpunit packages/alex-kassel/workspace-manifest/tests`.
- [ ] Run complete test suite for `alex-kassel/workspace-development-toolkit`: `vendor/bin/phpunit -c packages/alex-kassel/workspace-development-toolkit/phpunit.xml.dist`.
- [ ] Run Chaos test battery (`PackageCommandsChaosTest`, `WorkspaceCommandsChaosTest`, `StateChaosMachineTest`).

### 4.2. Static Analysis & Code Styling
- [ ] Ensure `vendor/bin/phpstan analyse` passes at Level 8 with 0 errors across all three packages.
- [ ] Run `vendor/bin/pint --format agent` across modified files.

### 4.3. Documentation & Agent Synchronization
- [ ] Update `README.md` in `workspace-development-toolkit` reflecting the clean 3-layer architecture.
- [ ] Review skills and AGENTS.md guidelines for complete accuracy.
