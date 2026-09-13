---
name: package-scaffolding
origin: alex-kassel/workspace-development-toolkit
version: 1.0.0
status: published
description: >-
  Use this skill when the user asks to create, initialize, or scaffold a new package in the workspace
  (e.g., "создай новый пакет", "создай библиотеку", "инициализируй пакет", "create package").
---

# Package Scaffolding Skill

This skill guides the deterministic, non-destructive creation of new packages in registered workspaces.

## Operational Workflow

1. **Clarify Package Identification**:
   - Determine `<vendor>` (`alex-kassel` for public open-source, `alex-privat` for pre-Packagist, `alex-local` for local domain modules).
   - Determine `<package-name>` (kebab-case).
   - Determine package archetype (`library` by default, `engine` for capability cores, `domain` for business domains). See [Archetypes Reference](./references/archetypes.md).

2. **Execute Scaffolding Generator**:
   Run the deterministic CLI scaffolding tool:
   ```bash
   php artisan package:make <vendor>/<package-name> --git
   ```
   *Tip: You can preview generated files first using `--dry-run`.*

3. **Verify Newly Scaffolded Package**:
   Immediately run the quality verification suite to ensure 100% green corridor:
   ```bash
   php artisan package:check <vendor>/<package-name> --json
   ```

4. **Implement Initial Package Logic**:
   - Add contracts, DTOs, and services in `src/`.
   - Keep commands and controllers strictly as thin coordinators: delegate process execution, file operations, error diagnostics, and business logic to reusable services + typed DTOs.
   - Add unit/feature tests in `tests/`.
   - Remember: **Strict No-Stubs Policy** (never leave empty methods, `// TODO`, or fake stubs).

5. **Commit Logical Unit**:
   Commit newly created files in the package's independent git repository and report commit hash to the user.
