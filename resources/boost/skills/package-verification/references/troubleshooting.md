# Package Verification & Database Testing Reference

## 1. Database & Migrations Testing Standard

### Isolation & Multi-Database Storage Contexts
In this ecosystem, database interactions follow two distinct patterns based on package archetype:

1. **Standalone Utility Packages (e.g. `workspace-development-toolkit`):**
   - Must be 100% autonomous.
   - Run tests in-memory using Orchestra Testbench with SQLite (`:memory:`).
   - Load migrations dynamically in `TestCase::defineDatabaseMigrations()` via `$this->loadMigrationsFrom(__DIR__ . '/../database/migrations')`.
   - **NEVER** run migrations or seeders against the host application database.

2. **Domain & Capability Engines (Contextual Multi-Database Packages):**
   - **Detection Markers**: Any package that depends on `laravel-domain-core`, injects `DomainRegistryInterface`, registers `StorageContext::database(...)`, or extends `AbstractScrapingServiceProvider`.
   - **Context-Aware Dynamic Migrations**: These packages route migrations dynamically into isolated domain databases (e.g., `sqlite_<domain>_raw`).
   - **STRICT PROHIBITION**: NEVER inject static `$this->loadMigrationsFrom()` or `$this->publishes()` into capability/domain service providers. Doing so destroys dynamic context routing and pollutes the host database.
   - Tests must register storage contexts via `DomainRegistryInterface` and execute migrations through `laravel-domain-core` migration runner.

---

## 2. Common Failure Patterns & Fixes

### Autoloader & Bootstrap Failures
- **Symptom**: `Class not found` or `Composer autoloader not found` in tests.
- **Cause**: Missing or incorrect `tests/bootstrap.php` or `phpunit.xml.dist`.
- **Fix**: Ensure `tests/bootstrap.php` uses dynamic fallback:
  ```php
  <?php
  declare(strict_types=1);
  $candidates = [__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../../vendor/autoload.php'];
  $autoloader = null;
  foreach ($candidates as $c) { if (file_exists($c)) { $autoloader = require $c; break; } }
  if ($autoloader === null) { throw new RuntimeException('Autoloader not found.'); }
  $autoloader->addPsr4('Vendor\\PackageName\\Tests\\', __DIR__);
  ```

### PHPStan Type Errors (Level 8)
- Add precise PHPDoc types (`/** @var array<string, mixed> $payload */`).
- Provide explicit return types on methods and typed properties.
- Avoid suppressing errors with baseline unless approved by the user.

### Pint Code Style Failures
- Execute `php artisan package:check <vendor>/<package> --fix` or `vendor/bin/pint --format agent` to auto-format code.
