---
name: package-refactoring
origin: alex-kassel/workspace-development-toolkit
version: 0.1.0
status: draft
description: >-
  Systematic, step-by-step methodology for deep architectural refactoring, API streamlining,
  zero-bloat DTO redesign, dead code elimination, and documentation synchronization for PHP/Laravel packages.
---

# Package Refactoring Skill (DRAFT)

> [!NOTE]
> This skill is currently in **DRAFT** status, being formulated and refined collaboratively through real-world package overhauls.

This skill provides a rigorous, battle-tested methodology for deep refactoring and modernizing standalone PHP and Laravel packages. It enforces Single Responsibility, minimal API surface, zero-bloat DTOs, container-first idioms, and 100% "Product Truth" in documentation.

---

## Non-Negotiable Standards & Code Conventions

### Strict Typing Declaration
Every PHP file created or refactored (both in `src/` and in `tests/`) **MUST** begin with the strict typing declaration:
```php
<?php

declare(strict_types=1);
```
No exceptions. This ensures strict scalar type enforcement across all boundaries and prevents subtle implicit type juggling.

---

```
[1. Root Hygiene] ➔ [2. Config Audit] ➔ [3. ServiceProvider] ➔ [4. Architecture & SRP] ➔ [5. API Streamlining]
                                                                                                  │
[10. Git & Commits] ◀── [9. Verification] ◀── [8. Docs Truth] ◀── [7. Alignment] ◀── [6. DTO Redesign]
```

---

### Step 1: Root Hygiene & Artifact Audit
Before touching source code or architecture, inspect the package root directory:
1. **Identify Root Files**: Run `ls -la` in the package root to identify all non-directory files and hidden entries.
2. **Handle Internal Folders (`.dev/`)**:
   - The `.dev/` folder is a legitimate internal workspace directory for design notes, roadmap, and use-cases.
   - It **MUST NOT** be added to `.gitignore` (it stays tracked in version control).
   - It **MUST** be present in `.gitattributes` with `export-ignore` so it is excluded from distribution archives.
3. **Handle Ephemeral Cache Files**:
   - Cache files like `.phpunit.result.cache` or `.phpunit.cache/` belong in `.gitignore`. If already ignored, they do not block refactoring, though keeping the working directory clean is good practice.
4. **Detect Stray/Garbage Files**:
   - Flag any unexpected dump files, ad-hoc scripts, or obsolete drafts in the root.
   - If found, prompt the user for direction before deleting or moving.

---

### Step 2: Configuration Audit & Report
Examine the package configuration surface:
1. **Locate Configuration**:
   - Check for `config/<package>.php`.
   - Check `ServiceProvider` for `$this->mergeConfigFrom(...)` and `$this->publishes(...)`.
2. **Usage & Value Analysis**:
   - Grep across `src/` for all `config(...)` usages.
   - Trace every configuration key: Is it actively utilized? Does it serve a real architectural purpose, or is it decorative bloat / dead code?
   - Can hardcoded sensible defaults replace premature configuration knobs?
3. **Zero-Config Verification**:
   - If the package has no config file (e.g. pure engine / library), verify whether zero-config is the right design and confirm that no hidden global `config()` lookups exist in the codebase.
4. **Configuration Report**:
   - Present a concise report to the user covering every config key (Key, Usage location, Purpose, Recommendation: Keep / Simplify / Remove).

---

### Step 3: ServiceProvider & Container Cleanliness
Whether creating a brand new package or refactoring an existing one, `ServiceProvider` must be ultra-lean, relying fully on Laravel's dependency injection container.

#### 1. Eliminate String Facade Accessors
- **Anti-pattern**: Defining `public const FACADE_ACCESSOR = 'my.package';`, returning strings from `getFacadeAccessor()`, and registering `$this->app->alias(MyService::class, 'my.package')`. This is an obsolete Laravel 4/5 holdover.
- **Idiomatic Standard**: Facades return the exact service FQCN:
  ```php
  // Facade:
  class Manifest extends Facade
  {
      protected static function getFacadeAccessor(): string
      {
          return ManifestManager::class;
      }
  }
  ```
  *Benefit:* IDE auto-completion, static analysis (PHPStan), refactoring tools, and zero need for aliases or string constants.

#### 2. Short-Form Singleton & Service Registrations
- **Anti-pattern**: Writing verbose closures with manual dependency wiring when the container can autowire them:
  ```php
  // BAD: Redundant closures, manual makes, and excessive whitespace
  $this->app->singleton(ManifestRegistry::class, function () {
      return new ManifestRegistry;
  });

  $this->app->singleton(ManifestManager::class, function ($app) {
      return new ManifestManager(
          files: $app->make(Filesystem::class),
          registry: $app->make(ManifestRegistry::class),
      );
  });
  ```
- **Idiomatic Standard**: Single-line class registrations without blank separator lines:
  ```php
  // GOOD: Container autowiring
  public function register(): void
  {
      $this->app->singleton(ManifestRegistry::class);
      $this->app->singleton(ManifestManager::class);
      $this->app->singleton(ManifestInspectionService::class);
  }
  ```
  *Rule:* Closures in `singleton()` / `bind()` are strictly permitted only when resolving dynamic runtime options or complex factory logic that cannot be autowired.

#### 3. Laravel-First Confidence
- **Anti-pattern**: Defensive checks against the framework:
  ```php
  basePath: function_exists('base_path') ? base_path() : null
  ```
- **Idiomatic Standard**: Our packages are designed Laravel-first. We embrace framework helpers (`base_path()`, `app_path()`, `config()`, `str()`) directly without defensive doubt. If a class defaults to base path, let its internal resolution use `base_path()` directly when null.

---

### Step 4: Root `src/` Audit & Component Decomposition (Bottom-Up Strategy)
Instead of immediately jumping into the main entry point or largest service, inspect the classes sitting directly in `src/` using a **bottom-up approach**:
1. **Identify Root Classes**: List all classes located directly under `src/` (not inside nested folders like `Services/`, `DTOs/`, `Console/`).
2. **Start Small (Low-Coupling Components)**: Begin auditing smaller, independent classes (e.g. `Registry`, `Resolver`, `Store`) before tackling heavy coordinator monoliths (`Manager`, `Engine`).
3. **Registry & Store Contract Standard**:
   - **Anti-pattern**: Accepting a sprawling list of loose parameters inside a registry method:
     ```php
     // BAD: Registry duplicates DTO constructor arguments
     public function register(string $name, string $filename, string|Schema $schema, ?string $desc = null, array $meta = []): self
     {
         $this->manifests[$name] = new ManifestDefinition($name, $filename, $schema, $desc, $meta);
         return $this;
     }
     ```
   - **Idiomatic Standard**: Registries must accept typed DTO instances directly:
     ```php
     // GOOD: Registry accepts pure typed definition DTO
     public function register(ManifestDefinition $definition): self
     {
         $this->manifests[$definition->name] = $definition;
         return $this;
     }
     ```
     *Rationale:* The DTO is already responsible for validating and holding its data. The Registry should only be responsible for storage, retrieval, and indexing.
4. **Use-Case Justification (Universal Package Context)**:
   - When reviewing registry/store methods, consider universal use cases, not merely immediate local needs:
     - `register(DTO $definition): self` — Essential.
     - `get(string $name): ?DTO` — Essential (pure nullable query).
     - `has(string $name): bool` — Essential (fast existence check).
     - `all(): array` — Essential (inspection, iteration, CLI commands).
     - `forget(string $name): self` — Essential for dynamic lifecycle, unregistering plugins, or test teardowns.
     - `clear(): self` — Useful for resetting state in test suites and long-running workers.
   - **Rule of Restraint**: Do NOT add speculative methods without concrete, logical necessity. You can always add a method later when a genuine requirement arises. Never bloat early.
5. **No Raw Array Constructor Injection in Registries**:
   - **Anti-pattern**: `public function __construct(protected array $items = [])`
   - **Risk**: PHP cannot enforce inner value types on arrays at runtime (`array<string, Definition>` is only a PHPDoc hint). Accepting a raw array allows invalid, corrupt, or unverified items to bypass type checks and pollute internal state.
   - **Idiomatic Standard**: Keep internal state unexposed in constructor:
     ```php
     protected array $items = [];
     ```
     Enforce that all entries enter the registry exclusively through the strictly-typed `register(Definition $item)` method.
6. **Manager & Coordinator Streamlining**:
   - **No Trampoline Dependencies**: Do NOT inject a dependency (e.g. `Filesystem`) into a Manager if the Manager never performs operations with it and only forwards it to child constructors. Let child objects resolve dependencies themselves.
   - **Public Readonly Child Services Over Proxy Methods**:
     - *Anti-pattern:* Writing forwarding wrapper methods (`has()`, `register()`) and getters (`registry()`) in a parent manager class.
     - *Idiomatic Standard:* Promote child services to `public readonly`:
       ```php
       public function __construct(
           public readonly ManifestRegistry $registry,
       ) {}
       ```
       Consumers interact directly (`$manager->registry->has(...)`), eliminating 50% of boilerplate code.
   - **No Redundant Base Path Parameters**:
     - Do not thread `$basePath` arguments through methods when standard files are inherently anchored to Laravel's `base_path()`.
   - **Single Decisive Retrieval Method (`get()`)**:
     - Do not proliferate speculative duplicate retrieval methods (`find()` returning `null` vs `get()` throwing). Provide a single decisive `get(string $name)` that fails with a typed `NotFoundException`. Existence checks belong on the registry (`$manager->registry->has($name)`).

---

### Step 5: Public API Streamlining ("Less is More")
*Eliminate vanity methods, maintain API symmetry, and enforce strict typing.*
1. **Unified Polymorphism**: Consolidate split methods (e.g. replace `fromFile()` / `fromDirectory()` with a polymorphic `from()`).
2. **Eliminate Redundant Steps**: Remove intermediate chaining methods that only wrap single options.
3. **Strict Parameter Types**: Avoid nullable parameters (`?string $path = null`) where an argument is logically required or defaults are explicit.

---

### Step 6: Result DTO Redesign (Zero-Bloat DTO)
*Strip fake conveniences from result objects in favor of transparent, typed data.*
1. **Remove Fake Abstractions**: Strip `Countable`, `Arrayable`, `toArray()`, `fileCount`.
2. **Remove Redundant Predicates**: Eliminate boolean helpers (`isSuccessful()`, `has*()`).
3. **Direct Public Readonly Properties**: Provide strongly-typed `public readonly` arrays/values. Direct inspection (`$result->items !== []`, `count($result->items)`) is faster, explicit, and self-documenting.

---

### Step 7: Workspace & Consumer Alignment
1. Search the workspace/monorepo for call-sites using old signatures of the refactored package.
2. Update consumer packages and actions immediately.
3. Verify test suites of all affected workspace packages.

---

### Step 8: Documentation Truth & Changelog
1. **Eliminate Phantom APIs**: Audit `README.md` and `docs/*.md` to remove references to removed or altered methods.
2. **Accurate Code Examples**: Ensure all snippet examples use the exact current signatures.
3. **Update CHANGELOG.md**: Document changes under `[Unreleased]` (Breaking Changes, Changed, Removed, Added).

---

### Step 9: Quality Verification
1. Run code formatting: `vendor/bin/pint --format agent`.
2. Run test suites: `vendor/bin/phpunit` across both the package and consumers.
3. Run static analysis if configured.

---

### Step 10: Git Hygiene & Atomic Commits
1. Group modifications into atomic, logical units (architecture, DTO, docs, etc.).
2. Write concise Conventional Commits messages.
3. Push each completed phase directly to GitHub (`origin/main`).
