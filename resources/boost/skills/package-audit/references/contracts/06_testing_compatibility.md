# AUDIT CONTRACT: 06_TESTING_COMPATIBILITY

## 1. Role & Objective
Act as a senior test engineer responsible for proving that a public Laravel package actually works across its declared support range.

Determine whether package behavior is sufficiently tested and whether declared PHP, Laravel, and database compatibility is real and proven by tests.

---

## 2. Scope of Investigation

### 1. Test Harness & Infrastructure
- Test runner configuration: PHPUnit (`phpunit.xml.dist` or `phpunit.xml`) / Pest (`Pest.php`).
- Integration with `orchestra/testbench` for full Laravel service container, config, and facade mocking.
- Clean and dynamic autoloader bootstrap without leaky parent monorepo dependencies.

### 2. Test Coverage & Assertion Quality
- **Core Logic & Paths**: Happy paths, edge cases, error paths, and custom exception assertions.
- **Database & State**: Real database integration tests, transaction rollback checks, migration tests.
- **Public API Coverage**: Verification that all documented public methods and contracts have active test coverage.
- **Assertion Quality**: Absence of brittle tests, vacuous assertions, over-mocking, or tests asserting private implementation details.

### 3. Boundary & Compatibility Matrix
- Audit declared support from `composer.json` (PHP versions, Laravel versions).
- Multi-database engine test runs (SQLite in-memory, MySQL, PostgreSQL where configured).
- Verify tests run cleanly under `vendor/bin/phpunit` without errors or warnings.

### 4. Cross-Platform Test Reliability
- **Path Comparisons**: Verify tests do not make brittle string assertions assuming POSIX forward slashes or Windows backslashes (use normalized path comparisons).
- **Temporary Files & Fixtures**: Ensure tests create temporary directories and files using `sys_get_temp_dir()` or Laravel virtual storage instead of hardcoded `/tmp` or `C:\Temp`.

---

## 3. Mandatory Rules & Boundaries
- **READ-ONLY in Phase 1**: Never modify package test files or assertions during Phase 1.
- **Evidence-Based Compatibility**: Do NOT claim compatibility merely because `composer.json` declares it; compatibility must be demonstrated through test execution.

---

## 4. Output Deliverables
The agent produces a human-readable Markdown report: `<run-dir>/reports/testing.md` containing:
- **AUDIT STATUS**: `PASS` | `FAIL` | `PARTIAL` | `BLOCKED` | `NOT_APPLICABLE`.
- **TEST EXECUTION SUMMARY**: Total tests, assertions, execution time, pass/fail metrics.
- **COMPATIBILITY MATRIX RESULTS**: Minimum vs stable versions, engine test outcomes.
- **FINDINGS & ASSERTIONS**: Detailed breakdown with failing assertions, reproduction commands, and recommendations.
