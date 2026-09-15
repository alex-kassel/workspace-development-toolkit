# Workspace Ecosystem Evolution & Refactoring Roadmap

This roadmap documents the phased plan for transitioning `alex-kassel/workspace-development-toolkit` and `alex-kassel/workspace-manifest` into production-grade, release-ready Open Source packages.

---

## Strategic Goals

1. **Clean Architectural Boundaries**:
   - `ManifestEngine`: Low-level universal file-backed JSON store with atomic file operations and concurrency locks.
   - `WorkspaceManifest`: Domain repository, DTO layer, and schema invariants for `workspace.json`.
   - `WorkspaceDevelopmentToolkit`: Developer experience, CLI orchestration, Composer/Git lifecycle management.
2. **True Standalone Isolation**:
   - Both extracted packages must be fully testable and installable independently via Packagist without implicit assumptions about host Laravel environments.
3. **Zero Technical Debt**:
   - Full test coverage across Unit, Feature, and Chaos test suites.
   - 100% clean PHPStan Level 8 static analysis.
   - Elimination of code duplication and manual cache invalidation.

---

## Phase 1: Workspace Manifest Refinement & Packagist Readiness

Goal: Complete edge-case hardening, DTO ergonomics, and standalone independence in `alex-kassel/workspace-manifest`.

- [ ] **1.1. Universal Schema Resolution**
  - [ ] Support dynamic resolution for `$schema` path: detect local monorepo path (`./packages/...`), composer vendor path (`./vendor/...`), or fallback to a canonical GitHub raw URL.
  - [ ] Ensure IDE validation works out-of-the-box in both local development and consumer projects.
- [ ] **1.2. Complete DTO Ingestion & Ergonomics (CQS Parity)**
  - [ ] Add `savePackage(PackageDefinition $package): self` to accept strongly-typed DTOs directly.
  - [ ] Add `saveWorkspace(WorkspaceDefinition $workspace): self` for holistic workspace persistence.
  - [ ] Provide fluent builder/mutation methods on `PackageDefinition` (e.g. `withAlias()`, `withSkills()`).
- [ ] **1.3. Global Multi-Workspace Conflict Prevention**
  - [ ] Expand collision checking in `WorkspaceManifest::addPackage()` to validate against **all registered workspaces**, preventing root-level directory collisions when multiple workspaces exist.
- [ ] **1.4. In-Memory Lookup Indexing (Performance Optimization)**
  - [ ] Implement an internal lookup index in `WorkspaceManifest` (`[canonicalName => PackageDefinition]`) to ensure `O(1)` query efficiency for large multi-package workspaces.
- [ ] **1.5. Standalone Test Isolation**
  - [ ] Decouple `tests/TestCase.php` from the host's `Tests\TestCase`.
  - [ ] Implement a standalone Orchestra Testbench setup that executes reliably when cloned and tested in isolation (`composer test`).

---

## Phase 2: Event-Driven Synergy & Cache Elimination

Goal: Leverage `ManifestEngine` events to eliminate manual cache management across `WorkspaceDevelopmentToolkit`.

- [ ] **2.1. Domain Event Dispatching in WorkspaceManifest**
  - [ ] Dispatch domain events on mutations:
    - `AlexKassel\WorkspaceManifest\Events\WorkspaceRegistered`
    - `AlexKassel\WorkspaceManifest\Events\WorkspaceUnregistered`
    - `AlexKassel\WorkspaceManifest\Events\PackageRecorded`
    - `AlexKassel\WorkspaceManifest\Events\PackageRemoved`
- [ ] **2.2. Event-Driven Cache Invalidation in Toolkit**
  - [ ] Subscribe Toolkit's `PackageResolver` and `WorkspaceManager` to `WorkspaceManifest` domain events.
  - [ ] Remove explicit, fragile `$this->clearCache()` calls throughout Toolkit services.
- [ ] **2.3. Native ManifestDto Integration**
  - [ ] Investigate implementing `ManifestDto` on `WorkspaceDefinition` and `PackageDefinition` to delegate serialization directly to `ManifestEngine::toDto()` / `ManifestEngine::saveDto()`.

---

## Phase 3: Toolkit Core Simplification & Legacy Purge

Goal: Deepen integration with `WorkspaceManifest` and eliminate redundant legacy code in `WorkspaceDevelopmentToolkit`.

- [ ] **3.1. Deprecate & Inline `ManifestRepository` in Toolkit**
  - [ ] Evaluate remaining methods in `AlexKassel\WorkspaceDevelopmentToolkit\Services\ManifestRepository`.
  - [ ] Move host recovery logic (`heal()` from root `composer.json`) to a dedicated Toolkit Action (`ReconstructWorkspaceAction`).
  - [ ] Inject `WorkspaceManifest` directly into `WorkspaceManager`, `PackageResolver`, and console commands.
- [ ] **3.2. Single Source of Truth for Path Resolution**
  - [ ] Refactor `PackageResolver` to consume `PackageDefinition::effectiveDirectory()` and `canonicalName()` for all disk mappings, eliminating parallel path calculation logic.
- [ ] **3.3. Verify Symmetrical CLI Commands**
  - [ ] Ensure all workspace and package commands (`workspace:register`/`workspace:unregister`, `package:make`/`package:delete`) use the new pipeline consistently.

---

## Phase 4: Full Regression & Certification

Goal: Verify resilience, documentation sync, and finalize release certification.

- [ ] **4.1. Comprehensive Test Execution**
  - [ ] Run full test suites:
    - `vendor/bin/phpunit packages/alex-kassel/workspace-manifest/tests`
    - `vendor/bin/phpunit packages/alex-kassel/workspace-development-toolkit/tests`
  - [ ] Run Chaos test battery (`PackageCommandsChaosTest`, `WorkspaceCommandsChaosTest`, `StateChaosMachineTest`).
- [ ] **4.2. Static Analysis & Code Style**
  - [ ] Run `vendor/bin/phpstan analyse` across all packages at Level 8.
  - [ ] Format all modified files with `vendor/bin/pint`.
- [ ] **4.3. Documentation & Skill Synchronization**
  - [ ] Synchronize `README.md` in `workspace-development-toolkit` reflecting the new modular manifest architecture.
  - [ ] Update AGENTS guidelines if any command conventions or workflows were modernized.
