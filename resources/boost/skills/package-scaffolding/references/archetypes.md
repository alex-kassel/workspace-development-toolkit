# Package Archetypes Reference

The workspace environment supports three distinct package archetypes:

## 1. Standalone Open-Source Library (`archetype=library`)
- **Default Location**: `packages/alex-kassel/<package-name>/`
- **Purpose**: General-purpose utility libraries designed for standalone Packagist publication (e.g. `workspace-development-toolkit`, `laravel-domain-core`, `stable-fingerprint`).
- **Dependencies**: Depends only on low-level dependencies (`illuminate/support`, PHP standard library). Never depends on host application or child domain packages.

## 2. Core Capability Engine (`archetype=engine`)
- **Default Location**: `packages/alex-kassel/<engine-name>`
- **Purpose**: Abstract capability frameworks providing base service providers, DTOs, interfaces, and base CLI commands (e.g. `scraper-core`).
- **Isolation & Migration Rules**:
  - **NO Host Asset Publishing**: Never publish migrations to root `database/migrations` via `publishes()`.
  - **Dynamic Storage Contexts**: Capability schema migrations reside in `database/migrations` within the package and are loaded dynamically into isolated SQLite/DB contexts (`sqlite_<domain>_raw`, etc.) by child domain service providers via `StorageContext::migrationPaths()`.
  - **Strict No `loadMigrationsFrom()`**: Do not call static `$this->loadMigrationsFrom()` in ServiceProviders. Migrations are executed dynamically per domain context via `laravel-domain-core`.

## 3. Domain Package (`archetype=domain`)
- **Default Location**: `packages/alex-kassel/<domain-name>`
- **Purpose**: Concrete business domain implementations (e.g. `car-subscription`, `notifier`).
- **Structure**: Extends capability service providers (`AbstractScrapingServiceProvider`), registers domain-specific connection settings, spiders, models, and domain views.
