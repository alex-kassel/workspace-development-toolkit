# AUDIT CONTRACT: 03_DATABASE

## 1. Role & Objective
Act as a senior database engineer specializing in Laravel packages.

Determine whether the package safely handles database schema, migrations, queries, transactions, indexing, concurrency, and multi-database compatibility.

---

## 2. Scope of Investigation

### 1. Migrations & Schema Lifecycle
- Migration structure: `up()` and `down()` methods, full reversibility, and idempotency.
- Absence of destructive operations or accidental data-loss risks on upgrade.
- Primary keys (UUID / ULID / BigIncrements), foreign keys, and explicit constraint names avoiding database identifier length limits.
- Nullability, default values, column types, and timestamp consistency.

### 2. Package Schema Isolation & Table Prefixes
- **Table Name Collisions**: Does the package create generic table names (e.g. `users`, `settings`, `logs`, `jobs`, `metadata`) without a clear package prefix or configurable table prefix?
- Column collision risks when extending host tables.
- Global schema assumptions that may conflict with host application database setups.

### 3. Query Performance & Optimization
- Query optimization: avoidance of N+1 query bugs, eager loading with `$with` or explicit queries.
- Indexing strategy: composite indexes on polymorphic columns (`*_type`, `*_id`), foreign key indexes, indexes on frequently filtered/sorted fields.
- Raw SQL scrutiny: parameter bindings, avoidance of non-standard SQL dialects.
- Performance on large datasets: use chunking or cursor pagination (`chunkById()`, `lazy()`).

### 4. Transactions & Concurrency
- Atomic multi-table updates wrapped in `DB::transaction()`.
- Concurrency and race condition safety: proper use of unique database constraints, `firstOrCreate()`, `updateOrCreate()`.
- Deadlock prevention and row-level locking (`lockForUpdate()`) where appropriate.

### 5. Multi-Database Engine Compatibility
- Audit against explicitly declared databases ONLY (SQLite, MySQL/MariaDB, PostgreSQL).
- Avoid driver-specific SQL in queries unless properly encapsulated in driver detection checks.
- Verify schema operations behave correctly on both SQLite (tests) and MySQL/Postgres (production).

---

## 3. Mandatory Rules & Boundaries
- **Dynamic / Programmatic Migration Management vs Static Publishing**:
  - Packages that manage migrations programmatically (e.g. via dynamic connection managers, custom migrators, tenant/domain isolation hooks) rather than standard global auto-discovery are valid architectural choices.
  - The auditor MUST NEVER flag missing `$this->loadMigrationsFrom()` or missing `$this->publishes()` in ServiceProviders if the package employs programmatic migration management.
- **READ-ONLY in Phase 1**: Never modify migration files or execute destructive database commands during Phase 1.
- **Nuanced Severity**:
  - Do NOT automatically classify an unprefixed table as a release blocker unless collision is plausible. Flag as `MAJOR` with `requires_human_decision: true`.

---

## 4. Output Deliverables
The agent produces a human-readable Markdown report: `<run-dir>/reports/database.md` containing:
- **AUDIT STATUS**: `PASS` | `FAIL` | `PARTIAL` | `BLOCKED` | `NOT_APPLICABLE` (if package has no database interaction).
- **SCHEMA & MIGRATION SUMMARY**: Tables created, prefixes, indexes, foreign keys, rollback verified.
- **DATABASE COMPATIBILITY MATRIX**: SQLite / MySQL / PostgreSQL verification results.
- **FINDINGS & QUERIES**: Detailed breakdown with SQL snippets, indexing recommendations, and reproduction evidence.
