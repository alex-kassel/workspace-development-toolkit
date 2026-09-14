# AUDIT CONTRACT: 05_COMPOSER_SUPPLY_CHAIN

## 1. Role & Objective
Act as a Composer package distribution and software supply-chain specialist.

Determine whether the package is correctly declared, installable, reproducible, and completely safe to publish to Packagist.

---

## 2. Scope of Investigation

### 1. Composer Metadata & Manifest Quality
- Validation of required fields in `composer.json`: `name`, `description`, `type: "library"`, `license` (valid SPDX identifier), `keywords`, `authors`.
- Package discovery configuration: `extra.laravel.providers` and `extra.laravel.aliases`.
- Strict schema validation: `composer validate --strict`.

### 2. Dependency Constraints & Hygiene
- **PHP Version Constraints**: Match declared language features (e.g. `^8.2 || ^8.3 || ^8.4`).
- **Framework Constraints**: Permissive constraints for `illuminate/*` (e.g. `^11.0 || ^12.0`) to avoid artificially locking consumers out of framework upgrades.
- **Dependency Segregation**:
  - `require`: Only essential runtime packages.
  - `require-dev`: All testing and analysis tools (`orchestra/testbench`, `phpunit/phpunit`, `pestphp/pest`, `phpstan/phpstan`, `laravel/pint`).
  - **Zero-Bloat Rule**: Tooling such as Pint, PHPStan, PHPUnit must NEVER appear in `require`.
- **Undeclared Dependencies**: Detect runtime classes used in `src/` without being declared in `require`.

### 3. Autoloading Accuracy
- Strict PSR-4 mapping for production code (`src/` -> `Vendor\\PackageName\\`).
- `autoload-dev` mapping for test suites (`tests/` -> `Vendor\\PackageName\\Tests\\`).
- Case-sensitive namespace matching directory structure.

### 4. Lockfile & CI Dependency Resolution (CRITICAL)
- **Library vs Application Rule**: Reusable package libraries (`"type": "library"`) MUST NOT commit `composer.lock` to git. Committing a lockfile to a library locks transitive dependencies to the author's local PHP version and breaks cross-version matrix tests.
- `composer.lock` MUST be listed in `.gitignore` and `.gitattributes` (`export-ignore`).
- **CI Dependency Resolution**: CI workflows MUST execute `composer update --prefer-dist --no-interaction --no-progress` instead of `composer install` to dynamically resolve compatible dependencies for each PHP runner version.

### 5. Release Artifact & Supply Chain Integrity
- **`.gitattributes` Export-Ignore**: Ensure `.gitattributes` enforces standard line endings (`* text=auto eol=lf`) and contains `export-ignore` for:
  - `tests/`, `.github/`, `.phpunit.cache/`, `phpunit.xml*`
  - `.audit/`, `.agents/`, `.env*`, `.editorconfig`, `.git*`
  - `phpstan.neon*`, `pint.json`
- **Distribution Archive Verification**: Test building the release zip (`git archive`) and verify that no dev files, tests, or internal tooling leak into the distribution artifact.

---

## 3. Mandatory Rules & Boundaries
- **READ-ONLY in Phase 1**: Never alter `composer.json` or git tags during Phase 1.
- Distinguish library packaging conventions from application requirements.

---

## 4. Output Deliverables
The agent produces a human-readable Markdown report: `<run-dir>/reports/composer.md` containing:
- **AUDIT STATUS**: `PASS` | `FAIL` | `PARTIAL` | `BLOCKED` | `NOT_APPLICABLE`.
- **SUPPLY CHAIN & ARTIFACT SUMMARY**: `composer validate` result, export-ignore verification, dependencies sanity.
- **FINDINGS & METADATA**: Detailed breakdown with reproduction commands and recommendations.
