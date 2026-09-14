---
name: package-docs
origin: alex-kassel/workspace-development-toolkit
version: 1.1.0
status: published
description: >-
  Standardized documentation lifecycle skill for PHP and Laravel packages.
  Orchestrates documentation scaffolding, synchronization, and drift review
  (e.g., "document package", "update readme", "review docs", "sync documentation", "scaffold docs").
---

# Package Documentation Lifecycle Skill

This skill provides an autonomous, engineering-grade documentation lifecycle framework for PHP and Laravel packages. It enforces the canonical **Documentation Policy v1.1**, applying Diátaxis architecture, strict Product Truth hierarchy, and systematic 3-level drift detection.

---

## Architecture & References

- [**Documentation Policy (v1.1)**](./references/policy.md) — Product Truth hierarchy, surface area taxonomy, snippet rules, definition of done.
- [**Badge Palette & README Skeleton**](./references/badge-palette-and-sections.md) — Canonical 5-badge color sequence, Hero markup, and copy-paste skeleton.
- [**README Profiles**](./references/profiles.md) — Structural profiles for `laravel-package`, `cli-tool`, `library`, and `workspace-toolkit`.
- [**Diátaxis System Guide**](./references/diataxis.md) — 4-quadrant methodology (Tutorial, How-to, Reference, Explanation).
- [**Drift Inspection Guide**](./references/drift-detection.md) — Mechanical, factual, and conceptual drift verification.
- [**Templates**](./resources/templates/) — Baseline README stubs for library and CLI tooling.

---

## Operating Modes

The skill operates in one of three distinct modes based on user intent:

```
┌─────────────────────────────────────────────────────────┐
│ 1. SCAFFOLD Mode                                        │
│ Generate initial documentation from Product Truth       │
├─────────────────────────────────────────────────────────┤
│ 2. SYNC Mode                                            │
│ Update existing documentation when code changes         │
├─────────────────────────────────────────────────────────┤
│ 3. REVIEW Mode                                          │
│ Audit existing documentation for drift and accuracy     │
└─────────────────────────────────────────────────────────┘
```

---

## Workflow Execution

### Phase 1: Source of Truth Discovery

Before generating or modifying any documentation, establish the **Product Truth**:

1. **Level 1: Executable Runtime Constraints**:
   - Inspect `composer.json` (`name`, `description`, PHP and Laravel version constraints, `extra.laravel`).
   - Inspect ServiceProvider class for registered commands, published configs, migrations, stubs.
   - Inspect `config/*.php` for default keys, values, and environment variable fallbacks.
   - Inspect public method signatures of Facades and primary Service classes.
2. **Level 2: Executable Behavior**:
   - Inspect passing tests (PHPUnit/Pest) to understand concrete usage patterns, expected exceptions, and return structures.
3. **Level 3: Human-Authored Intent**:
   - Read existing docblocks, prior README, and architecture notes.
   - *Hierarchy Rule*: In case of discrepancy, Level 1 > Level 2 > Level 3. Report any contradiction to the user.

---

### Phase 2: Mode Execution

#### Mode A: SCAFFOLD (`scaffold`)
Use when creating documentation for a new package or rewriting an empty/incomplete README.
1. Determine the package profile (`laravel-package`, `cli-tool`, `library`, or `workspace-toolkit`) from [`references/profiles.md`](./references/profiles.md).
2. Select appropriate template stub from [`resources/templates/`](./resources/templates/).
3. Populate all 9 Required Functional Invariants:
   - Hero & Identification
   - Why This Exists (Concrete problem statement)
   - Requirements (PHP & Laravel matrix)
   - Installation & Setup (`composer require` or `--dev`)
   - Quickstart (Copy-paste ready in < 60s)
   - Usage & Recipes
   - Reference (CLI / Configuration / API)
   - Testing
   - License
4. Ensure all code snippets are copy-paste ready with full `use` imports.

#### Mode B: SYNC (`sync`)
Use when code changes have been introduced (new command, altered config key, signature change, version bump).
1. Identify the diff in Level 1 truth (e.g. `git diff` on ServiceProvider, config, composer).
2. Apply the **Documentation Change Contract** ([`references/policy.md#71`](./references/policy.md)):
   - Update version constraints if `composer.json` bumped.
   - Add/modify CLI command signatures and options in CLI Reference.
   - Update config reference tables for any new/renamed keys.
   - Synchronize code snippets if public method signatures changed.
3. Preserve existing human narrative and custom recipes while updating facts.

#### Mode C: REVIEW (`review`)
Use when auditing existing documentation for correctness, completeness, and drift.
1. Perform **Mechanical Drift Inspection** ([`references/drift-detection.md#1`](./references/drift-detection.md)):
   - Check dead links, missing commands, unmentioned config keys.
2. Perform **Factual Drift Inspection** ([`references/drift-detection.md#2`](./references/drift-detection.md)):
   - Check method signatures, parameter types, exit codes against codebase.
   - Eliminate any unsupported factual claims or marketing exaggerations.
3. Perform **Conceptual Drift Inspection** ([`references/drift-detection.md#3`](./references/drift-detection.md)):
   - Verify that Quickstart reflects current idiomatic practice.
4. Output structured review findings with actionable diffs or apply fixes directly if requested.

---

## Definition of Done Verification

Before concluding any documentation task:
- [ ] Code snippets verified for import hygiene (all required `use` statements present).
- [ ] No hyperbolic, speculative, or unsupported factual claims.
- [ ] All 9 Functional Invariants present and clear.
- [ ] File formatting conforms to project markdown guidelines.