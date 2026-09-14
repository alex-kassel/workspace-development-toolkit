# AUDIT CONTRACT: 02_CODE_QUALITY

## 1. Role & Objective
Act as a strict senior PHP/Laravel code reviewer and static-analysis specialist.

Determine whether the package implementation is robust, strongly typed, maintainable, idiomatic, and friendly to modern IDEs and static analysis engines.

---

## 2. Scope of Investigation

### 1. PHP Compatibility & Strong Typing
- Consistent `declare(strict_types=1);` usage across all PHP files.
- Return types, parameter types, property types, nullable types, and union/intersection types on all class members.
- Declared PHP version constraints in `composer.json` matching actual language features in use (e.g. readonly properties, enums, typed constants).
- Avoidance of primitive obsession: use Enums, DTOs, and Value Objects for structured domain data.

### 2. Static Analysis & Baseline Integrity
- PHPStan configuration and configured strictness level (target Level 8+ or `max`).
- **Baseline Audit**: Inspect `phpstan-baseline.neon`. A baseline must NEVER be used as a rug under which to sweep genuine architectural defects or type errors. Unjustified baseline suppressions must be flagged.
- Generic type annotations where helpful (`Collection<int, Model>`, `array<string, mixed>`) and array shapes (`array{id: int, name: string}`).

### 3. Formatting & Code Style
- Code styling adhering to PSR-12 / PER-CS 2.0 via Laravel Pint (`pint --test`).
- Absence of unformatted, misaligned, or style-inconsistent code.

### 4. Code Correctness & Reliability
- Unreachable code, dead code, and impossible states.
- Domain-specific exception taxonomy (avoid throwing raw `\Exception` or `\RuntimeException`).
- No swallowed exceptions or empty `catch` blocks.
- Unsafe assumptions, duplicated logic, and excessive cyclomatic complexity.

### 5. Cross-Platform Code Standards
- **Path Separators**: Always use forward slashes (`/`) or `DIRECTORY_SEPARATOR` in PHP code. Flag any hardcoded backslashes (`\`) in file paths, generators, or migrations.
- **Case-Sensitive PSR-4 Integrity**: Verify exact casing match between file paths, directories, and namespace/class declarations to prevent silent autoloading failures on case-sensitive Linux filesystems.
- **Temporary Directories**: Use `sys_get_temp_dir()` or Laravel `storage_path()` rather than hardcoded `/tmp` or `C:\Temp`.

---

## 3. Mandatory Rules & Boundaries
- **READ-ONLY in Phase 1**: Never run `pint` without `--test` or edit files during Phase 1.
- **No Baseline Hiding**: Flag any baseline entry that conceals genuine bugs or typing deficits.
- **Missing Tools Rule**: If static analysis or linting tools are absent or fail to run, record them as `BLOCKED` or `PARTIAL` rather than assuming `PASS`.

---

## 4. Output Deliverables
The agent produces a human-readable Markdown report: `<run-dir>/reports/code_quality.md` containing:
- **AUDIT STATUS**: `PASS` | `FAIL` | `PARTIAL` | `BLOCKED` | `NOT_APPLICABLE`.
- **STATIC ANALYSIS SUMMARY**: Configured level, tool version, clean run vs baseline status.
- **CODE STYLE SUMMARY**: Pint compliance check results.
- **FINDINGS & DEFECTS**: Detailed breakdown with reproduction commands, code snippets, and remediation steps.
