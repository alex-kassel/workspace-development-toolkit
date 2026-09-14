# Documentation Policy (v1.1)

This policy establishes canonical documentation standards for PHP and Laravel packages maintained within the development workspace and ecosystem.

Every package MUST maintain accurate, truthful, and developer-friendly documentation. Documentation is an engineering artifact: it is bound to the codebase, versioned with the codebase, and verified alongside the codebase.

---

## 1. Product Truth & Hierarchy of Authority

Documentation MUST reflect reality. It describes what the software actually does, how it is configured, and how it behaves under execution. Marketing exaggerations, speculative features, or unreleased capabilities are strictly prohibited.

When documenting any package behavior or resolving discrepancies, the following **strict hierarchy of authority** MUST be observed:

```
┌─────────────────────────────────────────────────────────┐
│ Level 1: Executable Runtime Constraints                 │
│ composer.json requirements, PHP method signatures,      │
│ config/*.php default schemas, registered commands/SP    │
├─────────────────────────────────────────────────────────┤
│ Level 2: Executable Behavior                            │
│ Passing test suite (PHPUnit/Pest), test fixtures,       │
│ verified runtime execution in consumer sandboxes        │
├─────────────────────────────────────────────────────────┤
│ Level 3: Human-Authored Intent                          │
│ PHPDocs, inline code comments, ADRs, existing docs      │
└─────────────────────────────────────────────────────────┘
```

- **Contradiction Invariant**: Inconsistencies between levels (e.g., PHPDoc or README claims vs actual runtime method signature) MUST NEVER be resolved silently. The agent or developer must report the discrepancy and apply `Level 1 > Level 2 > Level 3` precedence.
- **Unsupported Factual Claim**: Any claim in documentation that cannot be corroborated by Level 1 or Level 2 truth is considered an *unsupported factual claim* and must be revised or eliminated.

---

## 2. Documentation Surface Area

Documentation obligations apply specifically to the package's **documented consumer-facing API surface**, not every internal implementation detail.

### 2.1 Surface Area Taxonomy
The documentation surface area consists of:

1. **Public API & Contracts**: Primary interfaces, public service classes, and contract implementations intended for consumer use. (Public visibility alone does *not* imply an obligation to document; internal helpers marked `@internal` are exempt).
2. **CLI Commands**: Signature, arguments, options, expected behavior, failure modes, and console outputs.
3. **Configuration**: Every configuration key in published config files with default value, valid data types, and operational effect.
4. **Installation & Discovery**: Composer constraints, Laravel ServiceProvider auto-discovery, published assets, and required migrations.
5. **Published Resources**: Configs, migrations, views, assets, and stubs published via `vendor:publish`.
6. **Environment Variables**: Every `.env` key introduced or consumed by the package.
7. **Events & Listeners**: Events dispatched by the package, payloads, and listenable hooks.
8. **Domain Exceptions**: Notable custom exceptions thrown by public methods and recommended consumer recovery paths.
9. **Operational Constraints**: Compatibility constraints (PHP versions, Laravel major versions, Octane safety, queue/worker persistence).
10. **Upgrade & Breaking Changes**: Documented migrations and changes between major/breaking versions.

---

## 3. Architecture: Diátaxis Methodology

Documentation is organized following the **Diátaxis** framework. Diátaxis is an organizational methodology, not a rigid folder mandate: small packages may structure these quadrants within a single `README.md`, while larger packages may expand them into dedicated guides or a `docs/` directory.

```
                    LEARNING-ORIENTED
                           │
            Tutorials      │    How-to Guides
         (First success)   │   (Solve a problem)
                           │
  ─────────────────────────┼─────────────────────────
  PRACTICAL                │              THEORETICAL
                           │
           Reference       │     Explanation
       (Information truth) │    (Understanding)
                           │
                    INFORMATION-ORIENTED
```

### 3.1 The Four Quadrants
- **Tutorial (Learning-oriented)**:
  - Guided, hands-on path taking a newcomer to their first successful result.
  - Implemented as the **Quickstart** section in the README.
- **How-to Guide (Problem-oriented)**:
  - Practical recipes addressing specific real-world tasks (e.g., *"How to configure multi-database tenancy"*).
- **Reference (Information-oriented)**:
  - Dry, authoritative, complete facts: commands, configuration tables, class/method signatures, events.
- **Explanation (Understanding-oriented)**:
  - Architectural reasoning, design decisions, trade-offs, and "Why This Exists".

---

## 4. Documentation Tiers (Determined by Surface Area)

Packages are categorized into documentation tiers based on their **functional surface area**, never by raw lines of code.

| Tier | Profile Description | Surface Area Criteria | Required Documentation Artifacts |
| :--- | :--- | :--- | :--- |
| **Tier 1: Minimal** | Focused single-purpose library, helper package, or internal utility. | 0–1 CLI commands, ≤ 3 config options, ≤ 2 primary classes. | Single `README.md` containing all four Diátaxis quadrants compactly. |
| **Tier 2: Standard** | Standard Laravel package or developer tool. | 2–5 CLI commands, moderate config, multiple services/facades, events. | Comprehensive `README.md` + detailed recipes/reference sections (or optional `docs/how-to/`). |
| **Tier 3: Platform** | Complex framework, multi-workspace toolkit, or multi-component engine. | > 5 CLI commands, complex config, multi-workspace flows, extensibility hooks. | Structured `README.md` as portal + dedicated `docs/` hierarchy (`docs/tutorials/`, `docs/how-to/`, `docs/reference/`, `docs/explanation/`). |

---

## 5. README Structure & Functional Invariants

The `README.md` is the universal entry point. It must deliver an exceptional developer experience for both human engineers and AI coding agents.

### 5.1 Required Functional Invariants
Every `README.md` MUST satisfy these functional invariants in logical sequence:

1. **Hero & Identification**:
   - Package name, authoritative purpose summary (1–2 sentences).
   - Relevant metadata badges (License, PHP/Laravel version support, Audit/Build status).
2. **Why This Exists / Problem Statement**:
   - The concrete problem this package solves and why alternative approaches were insufficient.
3. **Requirements**:
   - Explicit PHP version matrix, Laravel version matrix, required PHP extensions, system dependencies.
4. **Installation & Setup**:
   - Canonical `composer require` command (or `--dev`), service provider discovery status, publishing steps.
5. **Quickstart / First Success**:
   - The fastest path (under 60 seconds) to working value with a minimal copy-paste ready snippet.
6. **Usage & Recipes (How-to)**:
   - Primary workflows and common tasks clearly demarcated.
7. **Reference (CLI / Configuration / API)**:
   - Exhaustive breakdown of public commands, flags, config keys, and primary APIs.
8. **Testing & Verification**:
   - Instructions for executing the test suite (`composer test` or `php artisan test`).
9. **License & Credits**:
   - Author, maintainers, and license declaration.

> [!NOTE]
> Headings do not need to use rigid identical phrasing (e.g. "Why This Exists" vs "Problem Statement"), provided each functional invariant is clearly and cleanly represented.

---

## 6. Code Snippets & Examples Policy

Unverified, broken code examples destroy developer trust. All snippets must follow strict hygiene:

### 6.1 Snippet Categories
1. **Executable Snippets**: Must run directly as written against the package public API without syntax errors.
2. **Copy-Paste Ready Snippets**: Include explicit minimal setup (e.g. required `use` imports) so developers can paste them into a standard Laravel application without guessing missing namespaces.
3. **Illustrative Snippets**: Used only for conceptual architectural walkthroughs or pseudo-code. MUST be explicitly commented at the top:
   ```php
   // Illustrative: Conceptual architecture example
   ```

### 6.2 Snippet Rules
- Always declare strict types or relevant import namespaces in primary examples.
- Do not use imaginary methods or unsupported parameter flags.
- Do not mix consumer snippets with contributor-only instructions.

---

## 7. Lifecycle & Documentation Drift Detection

Documentation drift occurs when code evolves but documentation remains static. Documentation verification inspects three distinct drift layers:

```
┌─────────────────────────────────────────────────────────┐
│ Level 1: Mechanical Drift (Deterministic Proof)         │
│ Dead links, missing referenced files, invalid markdown  │
│ syntax, unmentioned registered artisan commands,        │
│ missing config keys from published configs.             │
├─────────────────────────────────────────────────────────┤
│ Level 2: Factual Drift (Cognitive Proof)                │
│ Method signature mismatch, parameter changes, obsolete  │
│ return types, unsupported feature claims, broken setup. │
├─────────────────────────────────────────────────────────┤
│ Level 3: Conceptual Drift (Semantic Review)             │
│ Primary developer workflow has shifted, architectural   │
│ explanation no longer reflects implementation design.   │
└─────────────────────────────────────────────────────────┘
```

### 7.1 Documentation Change Contract
Any pull request or code change that touches the following components MUST include corresponding documentation updates:
- Adding or modifying a CLI command or option.
- Adding or altering a default key in `config/*.php`.
- Adding or removing a public API method on a published service/facade.
- Bumping minimum PHP or framework requirements in `composer.json`.
- Modifying behavior of published stubs or migrations.

### 7.2 Audit & Certification Cadence (Stable vs Patch Releases)
- **Routine patches do NOT mandate full cryptographic re-auditing**: Routine bugfixes, minor documentation edits, or cosmetic patch releases (e.g. `1.2.0` -> `1.2.1`) do NOT require running a full certification cycle and re-issuing `AUDIT.json`.
- **Inherited Certification Invariant**: It is completely valid for a patch release to display or inherit the cryptographic audit certificate of its parent stable milestone (e.g. `1.2.0`).
- **Certification Triggers**: Full package audits and cryptographic re-certification are reserved for **designated stable milestones** (e.g. `1.0.0`, `1.5.0`, `2.0.0`) or significant architectural refactorings.

---

## 8. Definition of Done (DoD) for Documentation

Documentation for any package or feature is complete only when:
- [ ] All functional invariants are satisfied.
- [ ] All configuration options and CLI commands match Level 1 runtime truth.
- [ ] Code snippets are copy-paste ready with correct namespaces and verified against Level 2 tests.
- [ ] No hyperbolic, unsupported factual claims exist.
- [ ] All markdown links resolve correctly and formatting matches repository style.