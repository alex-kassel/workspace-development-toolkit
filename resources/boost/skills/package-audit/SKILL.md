---
name: package-audit
origin: alex-kassel/workspace-development-toolkit
version: 1.0.0
status: published
description: >-
  Use this skill EXCLUSIVELY when the user explicitly requests a full package audit and release certification
  (e.g., "audit package", "certify package", "full release audit", "certify for release").
---

# Package Audit & Release Certification Skill

> [!IMPORTANT]
> **AUDIT ACTIVATION CONTRACT & TOKEN CONSERVATION RULE**:
> This skill is an expensive, high-rigor governance procedure reserved strictly for formal release certification.
> - **DO NOT INVOKE OR EXECUTE THIS SKILL** during routine feature development, bugfixing, refactoring, or patch updates (`1.2.0` -> `1.2.1`).
> - For everyday quality checks, use `php artisan package:check <pkg> --quick` or `composer test` instead.
> - Execute this procedure **ONLY** when the human user explicitly instructs: *"audit package"*, *"prepare release certification"*, *"certify package for release"*.

---

## Directory Structure & Resources

- [**Master Orchestrator Guide**](./references/orchestrator.md): Lifecycle coordination, 3-section reporting protocol, and remediation rules.
- [**7 Specialized Audit Contracts**](./references/contracts/):
  - [`01_architecture_api.md`](./references/contracts/01_architecture_api.md) — Public API surface, "Laravel First" abstractions, Octane safety, BC safety.
  - [`02_code_quality.md`](./references/contracts/02_code_quality.md) — Strict types, PHPStan Level 8+/max, Pint formatting.
  - [`03_database.md`](./references/contracts/03_database.md) — Migrations, table prefixes, multi-DB compatibility.
  - [`04_security_isolation.md`](./references/contracts/04_security_isolation.md) — Container hijacking prevention, secret leaks, injection protection.
  - [`05_composer_supply_chain.md`](./references/contracts/05_composer_supply_chain.md) — `composer validate --strict`, export-ignore, lockfile hygiene.
  - [`06_testing_compatibility.md`](./references/contracts/06_testing_compatibility.md) — PHPUnit/Pest coverage, test isolation, Testbench.
  - [`07_consumer_release.md`](./references/contracts/07_consumer_release.md) — Fresh sandbox smoke test, README quickstart, `AUDIT.json`.
- **Local Run Artifacts**: Stored in target package `.audit/<timestamp>/` (gitignored).

---

## Operational Workflow

### Phase 0: Tooling Verification & Self-Bootstrapping
When explicitly invoked by the human user, check if `php artisan package:audit` is available:
```bash
php artisan list package:audit
```
- **If available**: Proceed directly to Phase 1.
- **If missing (Self-Bootstrapping)**:
  Install the audit engine into `require-dev`:
  ```bash
  composer require --dev alex-kassel/workspace-development-toolkit
  ```
- **Direct CLI Fallback**: If the environment prevents installing composer packages, directly execute the underlying tools (`vendor/bin/pint --test`, `vendor/bin/phpstan`, `vendor/bin/phpunit`, `composer validate --strict`).

---

### Phase 1: Cognitive Audit & Mandatory Human Gate (Read-Only)

1. **Mechanical Baseline Diagnosis**:
   Run the package audit in inspect mode (no certification issued):
   ```bash
   php artisan package:audit <path/to/package> --json --no-commit
   ```
2. **Cognitive Domain Evaluation**:
   Inspect the package code against the 7 specialized contracts in [`references/contracts/`](./references/contracts/).
3. **Compile Local Review Artifacts**:
   Store diagnostic details in `.audit/<timestamp>/`:
   - `01_baseline_inspection.md`
   - `02_remediation_plan.md`
4. **Compile Structured 3-Section Report for the Human**:
   - **Section 1: Baseline Findings & Risk Assessment**
   - **Section 2: Planned Automated Fixes**
   - **Section 3: Architectural Decisions Requiring Explicit Choice**
5. **🛑 MANDATORY STOP (Human Gate)**:
   **DO NOT PROCEED. DO NOT MODIFY CODE. DO NOT GENERATE CERTIFICATE.**
   Present the report to the user and wait for explicit confirmation.

---

### Phase 2: Remediation, CI Matrix Verification, Commit & Cryptographic Certification

Once the human user confirms and approves the plan:
1. **Apply Atomic Remediation & Documentation**:
   - Execute approved fixes in logical order (Code Quality -> Architecture -> Tests -> Docs).
   - **Audit Verification Badge in README**: Ensure the official audit verification badge is placed as the **very first badge** in `README.md` pointing to `AUDIT.json` (as codified in `package-docs` badge palette):
     ```markdown
     [![Audit Verified](https://img.shields.io/badge/Audit-Verified-10b981?logo=shield)](AUDIT.json)
     ```
   - Finalize `CHANGELOG.md` with the target release version and date.
2. **Commit & Push to Remote**:
   Stage changes and commit:
   ```bash
   git add .
   git commit -m "release: prepare audited release v{version}"
   git push origin <branch>
   ```
3. **Autonomous Remote CI Matrix Verification**:
   - The orchestrator autonomously monitors the remote CI pipeline using GitHub CLI:
     ```bash
     gh run list --limit 1
     gh run view <run-id>
     ```
   - Verify that all matrix jobs (all PHP versions and OS platforms, including Windows/Ubuntu) pass with 100% green status.
   - If any CI job fails, inspect logs (`gh run view <run-id> --log-failed`), apply remediation, commit, push, and re-verify until the entire matrix is green.
4. **Mechanical Verification & Cryptographic Certification**:
   Execute final certification against the committed, CI-verified state:
   ```bash
   php artisan package:audit <path/to/package> --target-version={version}
   ```
   This issues the tamper-proof **`AUDIT.json`** anchored to the immutable Git `tree_hash` and commit, and tags `audit/v{version}`.
5. **Release Tagging & Publishing**:
   - **Strict Tag Immutability & Packagist Warning**:
     - Published git tags are strictly immutable. Never delete, re-point, or force-push an existing tag (`git push --force` or tag overwrite is prohibited).
     - Packagist flags modified tags as suspicious tampering and will ignore or reject that release version.
     - Any subsequent changes post-release must increment the patch version (`vX.Y.(Z+1)`).
   - Create the semantic release tag and push commits and tags to remote:
     ```bash
     git tag -a v{version} -m "Release v{version}"
     git push origin <branch> --tags
     ```

6. **Archive Run Artifacts**:
   Save `FINAL-REPORT.md` into `.audit/<timestamp>/` for local audit trail.