# Documentation Drift Inspection Guide

Documentation drift occurs when code evolves while documentation remains unaltered. Drift breaks developer confidence and leads both human engineers and AI coding agents to make false assumptions.

Inspect drift systematically across three distinct layers:

---

## 1. Mechanical Drift (Deterministic Proof)

Mechanical drift can be verified through deterministic filesystem and syntax checks:

### Checklist:
- [ ] **Dead Links**: Every relative file link (e.g. `[config](config/audit.php)`) points to an existing file.
- [ ] **Command Completeness**: Every Artisan command registered in the package ServiceProvider appears in the CLI Reference.
- [ ] **Config Completeness**: Every key present in `config/*.php` appears in the Configuration Reference table.
- [ ] **Markdown Syntax**: Fenced code blocks have valid language identifiers (`php`, `bash`, `json`, `yaml`).
- [ ] **Composer Requirements**: Mentioned PHP and framework versions match the actual constraints in `composer.json`.

---

## 2. Factual Drift (Cognitive Proof)

Factual drift occurs when documentation asserts facts that do not match the Level 1 and Level 2 product truth.

### Checklist:
- [ ] **Class/Method Signatures**: Method names, parameter order, types, and return types in code examples match the actual PHP classes.
- [ ] **Behavioral Claims**: Documented defaults, flags, and exit codes match actual code implementations and passing test assertions.
- [ ] **Unsupported Claims**: No assertions exist regarding hypothetical features, planned adapters, or unreleased functionality unless explicitly marked as future work.
- [ ] **Snippets Import Hygiene**: Code examples include necessary `use` statements or fully qualified class names.

---

## 3. Conceptual Drift (Semantic Review)

Conceptual drift occurs when the core workflow or purpose of the package has evolved, leaving the narrative or explanation outdated.

### Checklist:
- [ ] **Primary Workflow Alignment**: Does the Quickstart show the current recommended idiomatic approach, or does it showcase an obsolete v1 workflow?
- [ ] **Architectural Narrative**: Does "Why This Exists" still explain the actual architecture, or has the package migrated (e.g. from an embedded helper to a standalone engine)?
- [ ] **Terminology Consistency**: Do the terms used in the docs match the domain vocabulary currently in the codebase?