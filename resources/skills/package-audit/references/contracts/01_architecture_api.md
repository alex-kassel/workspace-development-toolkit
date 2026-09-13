# AUDIT CONTRACT: 01_ARCHITECTURE_API

## 1. Role & Objective
Act as a strict principal Laravel & PHP software architect.

Determine whether the package has a coherent architecture, stable public contract, idiomatic Laravel integration following the **Laravel First** philosophy, and clean host application isolation for production release.

---

## 2. Scope of Investigation

### 1. Repository Architecture & Boundaries
- Namespaces and PSR-4 directory mapping.
- Domain / Application / Infrastructure layer separation where applicable.
- Dependency direction (inward dependencies only; packages must never reference consumer application code).
- Circular dependencies and inappropriate coupling.

### 2. "Laravel First" Philosophy & Framework Idioms
- **First Priority — Native Laravel Core**: Always prefer native framework abstractions:
  - Use `Illuminate\Support\Sleep` instead of native `sleep()`.
  - Use `Illuminate\Support\Facades\Process` or `Symfony\Component\Process\Process` instead of raw `exec()`, `shell_exec()`, `passthru()`.
  - Use `Illuminate\Support\Facades\Http` instead of raw cURL or raw Guzzle calls.
  - Use Laravel `Collection`, `Str`, and `Arr` utilities.
  - Use native Laravel Event Dispatcher, Cache, Storage, and Pipeline components.
- **Zero-Dependency Core Preference**:
  - The package SHOULD NOT pull in unnecessary external wrapper libraries (such as third-party package tools or custom boilerplate helpers) when standard Laravel framework classes fulfill the need.
  - Pure, canonical `Illuminate\Support\ServiceProvider` usage is the preferred standard.
- **Reinventing the Wheel Prohibited**:
  - Flag any custom reinvented wheels (custom micro-ORMs, custom dependency injection containers, custom HTTP clients, custom log parsers) as `CRITICAL FINDINGS`.

### 3. Laravel ServiceProvider & Package Lifecycle
- **Pure Native ServiceProvider**:
  - Idiomatic usage of standard Laravel methods: `$this->mergeConfigFrom()`, `$this->publishes()`, `$this->loadMigrationsFrom()`, `$this->loadViewsFrom()`, `$this->commands()`.
  - Clean separation between `register()` and `boot()`:
    - `register()`: MUST ONLY bind services into the container and merge configurations. Strictly NO database queries, event dispatching, or heavy resolution during `register()`.
    - `boot()`: Routes, event listeners, view composers, command registration, and publishable assets.
- **Package Discovery**: Valid `extra.laravel.providers` and `extra.laravel.aliases` in `composer.json`.
- **Container Bindings**: Deliberate choices between `bind()`, `singleton()`, and `scoped()`.
  - **Octane / Concurrency Safety**: Singletons MUST NOT hold request-specific state (such as the current HTTP request or authenticated user).

### 4. Public API Surface & Encapsulation
- Catalog of all public classes, interfaces, DTOs, value objects, traits, and enums.
- Catalog of public methods and method signatures.
- Clear demarcation between public API and internal implementation details (use `@internal` docblock tags on internal classes/methods).
- Absence of hidden side-effects or surprising behavior for consumer applications.

### 5. Backward Compatibility & SemVer
- Identify public contract boundaries sensitive to SemVer.
- Detect breaking changes (BC breaks) across tags:
  - Altered method parameter types or return types.
  - Added non-optional method arguments to public interfaces or classes meant for extension.
  - Removed or renamed public methods/classes.
- Inspect `CHANGELOG.md` to ensure changes are documented according to Keep-a-Changelog.

### 6. Host Application Impact & Isolation
- **Container Hijacking**: Ensure package does not overwrite existing host application container bindings without conditional guards (`bindIf()`, `singletonIf()`).
- **Config Pollution**: Package must never mutate host application configuration keys (e.g. `app.timezone` or `database.connections`) at runtime.
- **Global Middleware & Catch-Alls**: Package must not register intrusive global middleware or wildcard routes that intercept host application traffic.

### 7. Thin Coordinators, Reusability & Anti-Overengineering
- **Thin Commands & Handlers**:
  - Console commands and HTTP controllers MUST remain thin presentation coordinators.
  - Complex business operations, recursive dependency resolution, process execution parsing, and multi-step mutations MUST reside in dedicated, testable services.
  - Commands should only handle input parsing, service invocation, and terminal output formatting.
- **Reusability by Design**:
  - Service classes and utilities must be designed for independent reusability across commands, background jobs, or external consumer code without coupling to `Command` or `Request` instances.
  - Use structured, typed DTOs to return rich results instead of mixing data generation with terminal printing.
- **Anti-Overengineering (KISS & YAGNI)**:
  - Avoid speculative abstractions. Do not introduce interfaces, factories, or strategy patterns for simple operations that have only one implementation and no boundary mocking requirement.
  - Keep solutions direct, transparent, and pragmatically simple.
- **Ecosystem Leverage (No Homegrown Wheels)**:
  - For specialized domain problems (e.g., canonical payload hashing, specific protocol serialization), prefer established, focused, and tested Composer packages over homegrown ad-hoc reimplementations.
- **Human & Machine UX (Zero-Ambiguity)**:
  - All CLI commands, interactive prompts, error diagnostics, and documentation are designed for both human developers and autonomous AI agents.
  - Zero room for fantasy or guesswork: whenever asking for input or reporting errors, always provide the exact expected syntax/shape (e.g. two-part `vendor/package` such as `acme/my-pkg`) and concrete examples.
  - Never blur terminology (if an entity is a package, call it `package`, never "package or alias"; if an input requires a two-part `vendor/package`, explicitly specify the requirement).
  - All errors must provide an actionable, copy-pasteable resolution command (`How to fix:`).

---

## 3. Mandatory Rules & Boundaries
- **READ-ONLY in Phase 1**: Never modify package or host source code during Phase 1.
- **Evidence-Based**: Cite exact file paths, line numbers, and symbol names for every finding.
- **Pragmatism**: Do not demand interfaces for every internal class or treat design patterns as dogma. Flag architectural issues only when there is tangible maintainability, compatibility, correctness, or ecosystem impact.

---

## 4. Output Deliverables
The agent produces a human-readable Markdown report: `<run-dir>/reports/architecture.md` containing:
- **AUDIT STATUS**: `PASS` | `FAIL` | `PARTIAL` | `BLOCKED` | `NOT_APPLICABLE` with concise rationale.
- **PUBLIC API SURFACE SUMMARY**: Catalog of exposed contracts, classes, facades, events, and published configs.
- **LARAVEL INTEGRATION EVALUATION**: ServiceProvider hygiene, Octane safety, and framework abstraction adherence.
- **FINDINGS & ARCHITECTURAL RISKS**: Detailed breakdown with reproduction evidence and concrete recommendations.
