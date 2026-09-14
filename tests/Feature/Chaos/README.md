# Workspace Toolkit Chaos & Crash Test Suite

Industrial-grade crash and resilience testing suite for `alex-kassel/workspace-development-toolkit`.

This suite subjects console commands to malicious inputs, path traversal exploits, destructive filesystem operations, out-of-band state sabotage, and pseudo-random permutation sequences while guaranteeing full reproducibility and zero-maintenance self-growth.

---

## Core Philosophy

1. **Self-Growing (Zero-Maintenance Discovery)**:
   Tests do not rely on hardcoded command lists. Commands are discovered dynamically via PHP Reflection. When a new command class (e.g., `src/Commands/PackagePublishCommand.php`) is added to the toolkit, it is automatically fuzzed without modifying test files.
2. **Deterministic Reproducibility (`CHAOS_SEED`)**:
   Every randomized state-machine run is driven by a pseudo-random generator with an explicit seed. If a failure occurs on Step 17 of a 30-step sabotage sequence, the test prints an exact copy-paste command to reproduce that identical sequence down to the byte.
3. **Hermetic Host & Boundary Isolation**:
   Destructive commands (`package:delete`, `workspace:unregister`, `workspace:sync --clean`) are exercised against honey-pot traps (`.env`, project `composer.json`, alien directories). Cryptographic SHA256 snapshots verify that no files outside the targeted package are ever deleted, modified, or polluted.

---

## Directory Structure

```
tests/
├── Support/Chaos/
│   ├── CommandDiscovery.php       # Discovers all concrete console commands dynamically
│   ├── FuzzPayloadGenerator.php    # Malicious inputs: traversal, null bytes, long strings, emoji
│   ├── FilesystemSnapshot.php      # Recursive SHA256 integrity snapshot for boundary defense
│   ├── SabotageEngine.php          # Out-of-band sabotage: corrupting manifests, deleting files
│   └── ChaosSeedRunner.php         # Deterministic PRNG runner with breadcrumb failure tracing
└── Feature/Chaos/
    ├── README.md                   # This documentation
    ├── CommandFuzzingChaosTest.php    # Self-growing argument and option fuzzer (500+ assertions)
    ├── FileSystemBoundaryChaosTest.php# Host boundary and protected files integrity guard
    ├── StateChaosMachineTest.php      # Property-based state machine with reproducible seeds
    ├── PackageCommandsChaosTest.php   # Fast regression contracts for package commands
    └── WorkspaceCommandsChaosTest.php # Fast regression contracts for workspace commands
```

---

## Running the Tests

### 1. Run the Entire Chaos Suite

```bash
./vendor/bin/phpunit -c packages/alex-kassel/workspace-development-toolkit/phpunit.xml.dist packages/alex-kassel/workspace-development-toolkit/tests/Feature/Chaos
```

### 2. Run the Command Fuzzer

Fuzzes all discovered commands with path traversal, null bytes, and extreme strings:

```bash
./vendor/bin/phpunit -c packages/alex-kassel/workspace-development-toolkit/phpunit.xml.dist packages/alex-kassel/workspace-development-toolkit/tests/Feature/Chaos/CommandFuzzingChaosTest.php
```

### 3. Run the Boundary Protection Guard

Validates that destructive operations refuse to delete protected or alien files:

```bash
./vendor/bin/phpunit -c packages/alex-kassel/workspace-development-toolkit/phpunit.xml.dist packages/alex-kassel/workspace-development-toolkit/tests/Feature/Chaos/FileSystemBoundaryChaosTest.php
```

### 4. Run the Deterministic State Machine

Executes a randomized sequence of commands and out-of-band sabotages:

```bash
./vendor/bin/phpunit -c packages/alex-kassel/workspace-development-toolkit/phpunit.xml.dist packages/alex-kassel/workspace-development-toolkit/tests/Feature/Chaos/StateChaosMachineTest.php
```

### 5. Reproducing a Specific Failure via `CHAOS_SEED`

When `StateChaosMachineTest` fails, the output prints a detailed breadcrumb trace and the exact seed:

```bash
CHAOS_SEED=3330443699 ./vendor/bin/phpunit -c packages/alex-kassel/workspace-development-toolkit/phpunit.xml.dist packages/alex-kassel/workspace-development-toolkit/tests/Feature/Chaos/StateChaosMachineTest.php
```

---

## Test Components Explained

### 1. `CommandFuzzingChaosTest`
* **Discovery**: Calls `CommandDiscovery::allCommands()` to reflect on `src/Commands/*Command.php`.
* **Traversal Vectors**: Injects `../../../../etc/passwd`, `..\..\..\windows`, `packages/../../secret`, `/etc/shadow`, `.`, `..`, `/`, `//`, `~/`, and null bytes (`\0`).
* **Extreme Vectors**: Injects empty strings, whitespace, illegal characters (`@#$%^&*()`), emojis, HTML tags, and strings exceeding filesystem limits (> 1000 characters).
* **Option Fuzzing**: Tests path traversal in `--workspace`, `--as`, `--alias`, and `--file`.
* **Assertion Contract**: No command may terminate with an unhandled exception (`TypeError`, `ErrorException`, unhandled 500-level throwables). In non-interactive mode, commands must reject invalid input gracefully with exit code `1` (`Command::FAILURE`) and clear user guidance.

### 2. `FileSystemBoundaryChaosTest`
* **Honey-Pots Planted**: Root `.env`, root `composer.json`, root `.gitignore`, root `README.md`, protected neighbor packages (`packages/acme/protected-pkg`), and alien unmanaged folders.
* **Attacks Executed**: `package:delete . --force`, `package:delete packages --force`, `package:delete ../ --force`, `workspace:unregister /`, `package:delete alien-folder --force`.
* **Assertion Contract**: `FilesystemSnapshot::verifyUntouched()` asserts that every protected file exists with its identical SHA256 hash and no rogue files were created in protected directories.

### 3. `StateChaosMachineTest`
* **Random Actions**:
  - `package:make` with random vendors and suffixes.
  - `package:delete` targeting existing or ghost packages.
  - `workspace:sync`, `workspace:status`, `package:check`, `package:deps`, `package:readme`.
* **Out-of-Band Sabotages**:
  - Overwriting `workspace.json` with broken JSON syntax (verifying automatic self-healing).
  - Deleting package directories directly from disk (`File::deleteDirectory()`) behind the toolkit's back.
  - Corrupting `composer.json` inside a package.
  - Injecting alien unmanaged directories into the workspace folder.
* **Assertion Contract**: The manifest self-heals or remains valid JSON at every step; no unhandled crashes occur.

---

## Extending the Chaos Suite

### Adding New Fuzzing Vectors
To add new malicious payloads (e.g. SQL injection strings, unicode normalization quirks, Windows reserved filenames like `CON`, `PRN`, `AUX`), add them to `tests/Support/Chaos/FuzzPayloadGenerator.php`:

```php
public static function malformedInputs(): array
{
    return [
        // Add new attack vectors here
        'CON', 'PRN', 'AUX', 'NUL',
    ];
}
```
All commands in `CommandFuzzingChaosTest` will automatically test the new vectors on the next run.

### Adding New Sabotage Actions
To test a new type of filesystem corruption (e.g. read-only permissions via `chmod`, broken symlinks, detached Git HEAD), add a static helper in `tests/Support/Chaos/SabotageEngine.php` and wire it into the `switch ($actionType)` block in `tests/Feature/Chaos/StateChaosMachineTest.php`.

---

## Guidelines for AI Coding Agents

When developing new features or fixing bugs in this repository:
1. **Always run the chaos suite before finalizing changes**:
   ```bash
   ./vendor/bin/phpunit -c packages/alex-kassel/workspace-development-toolkit/phpunit.xml.dist packages/alex-kassel/workspace-development-toolkit/tests/Feature/Chaos
   ```
2. **Never catch and silence exceptions blindly**:
   If a command fails on invalid input, it must return `self::FAILURE` (1) with an actionable `<comment>How to fix:</comment>` line following the zero-ambiguity guidelines.
3. **Protect filesystem boundaries**:
   Always sanitize paths using `FilesystemHelper::canonicalPath()` before calling destructive filesystem operations (`File::deleteDirectory()`, `File::delete()`, `File::put()`).
