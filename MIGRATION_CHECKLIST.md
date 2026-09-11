# Migration Checklist: dev-kit → workspace-development-toolkit

> Пошаговый чеклист переноса фич из `storage/reference/dev-kit` в этот пакет.
> Каждый шаг самодостаточен и может быть выполнен отдельным агентом.

---

## Контекст для агента

**Актуальный пакет:** `packages/alex-kassel/workspace-development-toolkit`
**Референсный пакет (read-only, не модифицировать):** `storage/reference/dev-kit`

### Философия текущего пакета (ОБЯЗАТЕЛЬНО соблюдать)

- Все ошибки — через domain exceptions с `getSolution()` и actionable `<comment>How to fix:</comment>` hints.
- Сервисы инъектируются через Laravel container (singletons в `ServiceProvider`).
- Команды делегируют логику сервисам, не содержат бизнес-логику.
- `Process::fake()` в тестах для внешних вызовов (composer, git).
- Sandbox-тесты: `TestCase` создаёт tmp-директорию как `basePath`, сбрасывает container.
- НЕ добавлять новые зависимости в `composer.json` без одобрения.
- НЕ добавлять `phpunit`, `phpstan`, `pint` в зависимости пакета — пакет полагается на бинарники хоста.
- Запустить `vendor/bin/pint --dirty --format agent` после любых изменений в PHP-файлах.

### Антипаттерны (ЗАПРЕЩЕНО)

1. НЕ добавлять заглушки, fallbacks, retry-loops с `usleep()`.
2. НЕ парсить markdown regex'ами для извлечения структурированных данных.
3. НЕ подменять протокол URL молча (SSH→HTTPS) — ошибка + hint.
4. НЕ модифицировать файлы хоста (`AGENTS.md`, `README.md`, `boost.json`) без явного запроса.
5. НЕ использовать `Process::pool()` для мутирующих операций — только для read-only проверок.
6. НЕ создавать «оптимизации» тестирования, пропускающие проверки или фальсифицирующие результаты.
7. НЕ добавлять Termwind, FileLock, или другие избыточные зависимости.

---

## Фаза 1: Расширенный Scaffolding (Стабы)

### Step 1.1 — Создание стабов

- [x] Создать `stubs/package/` с файлами:
  - `CHANGELOG.md.stub` — Keep-a-Changelog шаблон (`## [Unreleased]`)
  - `README.md.stub` — базовый README с плейсхолдерами (`{{ vendor }}`, `{{ package }}`, `{{ vendorNamespace }}`, `{{ packageNamespace }}`)
  - `phpunit.xml.stub` — PHPUnit 11/12 конфиг (testsuite `Unit`, source `src/`)
  - `phpstan.neon.stub` — Level 8 на `src/`, `tmpDir: build/phpstan`
  - `gitattributes.stub` — `* text=auto eol=lf` + `export-ignore` для `/tests`, `/.github`, `/phpunit.xml`, `/phpstan.neon`, `/.gitignore`, `/.gitattributes`
  - `gitignore.stub` — `/vendor/`, `/.phpunit.cache/`, `/build/`, `.env`, `composer.lock`
  - `TestCase.php.stub` — базовый Orchestra Testbench TestCase, регистрирующий provider в `getPackageProviders()`
  - `bootstrap.php.stub` — autoloader-резолвер для тестов (кандидаты: `../vendor/autoload.php`, `../../../../vendor/autoload.php`)

**Референс:** `storage/reference/dev-kit/stubs/` — содержит аналогичные стабы, но адаптировать под текущую архитектуру.

**Плейсхолдеры:** `{{ vendor }}`, `{{ package }}`, `{{ vendorNamespace }}`, `{{ packageNamespace }}`, `{{ year }}`, `{{ illuminate_constraint }}`, `{{ providerClass }}`.

### Step 1.2 — Публикация стабов

- [x] В `WorkspaceDevelopmentToolkitServiceProvider` зарегистрировать:
  ```php
  $this->publishes([
      __DIR__.'/../stubs/package' => base_path('stubs/workspace'),
  ], 'workspace-stubs');
  ```

### Step 1.3 — Расширение `PackageMakeCommand`

- [x] Расширить `package:make` для генерации полного набора файлов из стабов.
- [x] Логика выбора стаба: `base_path('stubs/workspace/{name}.stub')` → fallback на `__DIR__.'/../../stubs/package/{name}.stub'`.
- [x] Добавить флаг `--git` для `git init` + initial commit после scaffolding.
- [x] Добавить генерацию файлов: `.gitattributes`, `.gitignore`, `phpunit.xml`, `phpstan.neon`, `CHANGELOG.md`, `README.md`, `tests/TestCase.php`, `tests/bootstrap.php`, `tests/Unit/.gitkeep`.

### Step 1.4 — Тесты для расширенного scaffolding

- [x] Тест: `package:make` генерирует все файлы стабов.
- [x] Тест: `package:make --git` инициализирует git и создаёт commit.
- [x] Тест: пользовательские стабы из `stubs/workspace/` имеют приоритет.
- [x] Тест: плейсхолдеры заменены корректно во всех стабах.

---

## Фаза 2: Quality Gate (`package:check`)

### Step 2.1 — Сервис `PackageVerifier`

- [ ] Создать `src/Services/PackageVerifier.php` с методами:
  - `checkComposer(string $packagePath): CheckResult` — `composer validate --strict`
  - `checkPint(string $packagePath, bool $fix = false): CheckResult` — `vendor/bin/pint --test` (или `vendor/bin/pint` при `$fix`)
  - `checkPhpstan(string $packagePath): CheckResult` — `vendor/bin/phpstan analyse` (использует `phpstan.neon` пакета)
  - `checkTests(string $packagePath): CheckResult` — `vendor/bin/phpunit` (или `vendor/bin/pest`)
  - `checkAll(string $packagePath, array $only = [], bool $fix = false): array<CheckResult>`
- [ ] Бинарники резолвить из `base_path('vendor/bin/')`. Если бинарник не найден — `CheckResult` со статусом `skipped` и actionable message.
- [ ] На Windows проверять `.bat` варианты бинарников.

**Референс:** `storage/reference/dev-kit/src/Services/PackageVerifier.php`

### Step 2.2 — DTO `CheckResult`

- [ ] Создать `src/DTOs/CheckResult.php`:
  ```php
  final readonly class CheckResult
  {
      public function __construct(
          public string $check,           // 'composer', 'pint', 'phpstan', 'tests'
          public string $package,          // 'vendor/package'
          public string $status,           // 'passed', 'failed', 'skipped'
          public string $output,
          public float $durationSeconds,
      ) {}
  }
  ```

### Step 2.3 — Команда `PackageCheckCommand`

- [ ] Создать `src/Commands/PackageCheckCommand.php`:
  - Signature: `package:check {name?} {--all} {--fix} {--only=} {--isolated}`
  - Без аргумента `name` при наличии `--all` — проверяет все пакеты.
  - `--only=pint,phpstan` — подмножество проверок.
  - `--fix` — Pint в режиме автоисправления.
  - `--isolated` — делегирует в `IsolatedPackageVerifier` (Step 3).
- [ ] Зарегистрировать в `ServiceProvider`.

### Step 2.4 — Конфигурация проверок

- [ ] Расширить `config/workspace.php`:
  ```php
  'quality_checks' => [
      'composer_validate' => true,
      'pint' => true,
      'phpstan' => ['enabled' => true, 'level' => 8],
      'tests' => true,
  ],
  ```

### Step 2.5 — Параллельная проверка всех пакетов

- [ ] В `PackageVerifier` добавить метод `checkAllPackages(array $packagePaths, ...): array` с `Process::pool()`.
- [ ] `Process::pool()` только для read-only проверок (Pint `--test`, PHPStan, PHPUnit).

### Step 2.6 — Тесты для `package:check`

- [ ] Тест: `package:check vendor/pkg` запускает все 4 проверки.
- [ ] Тест: `package:check --only=pint` запускает только Pint.
- [ ] Тест: `package:check --fix` запускает Pint в режиме fix.
- [ ] Тест: проверка пакета с отсутствующим бинарником → `skipped`.
- [ ] Тест: `package:check --all` обнаруживает все пакеты.

---

## Фаза 3: Isolated Package Verification

### Step 3.1 — Сервис `IsolatedPackageVerifier`

- [ ] Создать `src/Services/IsolatedPackageVerifier.php`.
- [ ] Алгоритм:
  1. Экспорт tracked-файлов: `git ls-files --cached --others --exclude-standard -z` → temp dir.
  2. Проверка: `composer.json` пакета НЕ содержит path-репозиториев.
  3. Требование: `phpunit.xml` или `phpunit.xml.dist` существует.
  4. Env: `COMPOSER=false`, `COMPOSER_HOME=<temp>`, `COMPOSER_VENDOR_DIR=<temp>/vendor`.
  5. Маппинг `COMPOSER_CACHE_DIR` из хоста (Windows: `%LOCALAPPDATA%/Composer`, Linux: `~/.cache/composer`, macOS: `~/Library/Caches/composer`).
  6. Testing env: `APP_ENV=testing`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`.
  7. `composer install --prefer-dist --no-interaction --no-progress`.
  8. PHPUnit/Pest с `--fail-on-empty-test-suite`.
  9. Cleanup в `finally` через `FilesystemHelper::deleteDirectoryRecursively()`.
- [ ] Зарегистрировать как singleton в `ServiceProvider`.

**Референс:** `storage/reference/dev-kit/src/Services/IsolatedPackageVerifier.php`

### Step 3.2 — Интеграция с `PackageCheckCommand`

- [ ] Флаг `--isolated` в `PackageCheckCommand` делегирует в `IsolatedPackageVerifier`.

### Step 3.3 — Тесты для isolated verification

- [ ] Тест: isolated verification отклоняет пакет с path-репозиторием в composer.json.
- [ ] Тест: isolated verification отклоняет пакет без phpunit.xml.
- [ ] Тест: env переменные правильно инъектируются.
- [ ] Тест: cleanup вызывается даже при ошибке (finally).

---

## Фаза 4: Git Safety & Release Management

### Step 4.1 — Сервис `GitInspector`

- [ ] Создать `src/Services/GitInspector.php`:
  ```php
  class GitInspector
  {
      public function isClean(string $path): bool;
      public function hasUnpushedCommits(string $path): bool;
      public function hasStashes(string $path): bool;
      public function isDetachedHead(string $path): bool;
      public function getCurrentBranch(string $path): ?string;
      public function getLatestTag(string $path): ?string;
      public function hasRemote(string $path): bool;
      public function hasGitRepository(string $path): bool;
  }
  ```
- [ ] Зарегистрировать как singleton в `ServiceProvider`.

### Step 4.2 — Git Safety в `PackageDeleteCommand`

- [ ] Перед удалением проверять: clean working tree, no unpushed commits, no stashes.
- [ ] `--force` обходит все проверки.
- [ ] Actionable errors в стиле пакета.

### Step 4.3 — README Validator

- [ ] Создать `src/Services/ReadmeValidator.php`.
- [ ] Правила (конфигурируемые):
  - Файл `README.md` существует.
  - Содержит заголовок `# ...`.
  - Содержит секции из конфига (default: `Requirements`, `Installation`, `Usage`, `Testing`, `License`).
  - Нет unfilled placeholder'ов (`<vendor>/<package>`, `Vendor\Package`).
- [ ] Каждая проверка → `{rule: string, passed: bool, message: string}`.

**Референс:** `storage/reference/dev-kit/src/Services/ReadmeValidator.php` — но **НЕ** копировать жёсткий badge palette (#10b981 и т.д.). Сделать проверки конфигурируемыми.

### Step 4.4 — Команда `PackageReadmeCommand`

- [ ] Создать `src/Commands/PackageReadmeCommand.php`:
  - Signature: `package:readme {name}`
  - Резолвит пакет, запускает `ReadmeValidator`, выводит результат.

### Step 4.5 — Конфигурация README

- [ ] Расширить `config/workspace.php`:
  ```php
  'readme' => [
      'required_sections' => ['Requirements', 'Installation', 'Usage', 'Testing', 'License'],
  ],
  ```

### Step 4.6 — Release Pre-flight

- [ ] Создать `src/Services/ReleaseChecker.php` с гейтами:
  1. Git cleanliness (через `GitInspector`).
  2. Export-ignore (парсить `.gitattributes`).
  3. Code quality (делегировать `PackageVerifier`).
  4. README compliance (делегировать `ReadmeValidator`).
- [ ] Вердикт: `READY`, `ACTION_REQUIRED`, `BLOCKED`.

### Step 4.7 — Команда `PackageReleaseCheckCommand`

- [ ] Создать `src/Commands/PackageReleaseCheckCommand.php`:
  - Signature: `package:release-check {name} {--fast}`
  - `--fast` пропускает isolated verification.

### Step 4.8 — Тесты для Фазы 4

- [ ] Тесты: `GitInspector` — clean/dirty/unpushed/stashes.
- [ ] Тесты: `PackageDeleteCommand` блокирует удаление dirty пакета.
- [ ] Тесты: `ReadmeValidator` — missing file, missing sections, placeholders.
- [ ] Тесты: `ReleaseChecker` — READY/ACTION_REQUIRED/BLOCKED.
- [ ] Тесты: `package:release-check` — fast vs full.

---

## Фаза 5: Улучшение Inventory

### Step 5.1 — Расширение `workspace:list`

- [ ] Добавить столбцы: `Installed` (yes/no), `Version`.
- [ ] Использовать `Composer\InstalledVersions::isInstalled()` и `::getVersion()`.
- [ ] Symlink detection: `is_link(base_path("vendor/{$name}"))`.

### Step 5.2 — Тесты

- [ ] Тест: `workspace:list` отображает installed/version информацию.

---

## Фаза 6: Recursive Cloning

### Step 6.1 — Конфигурация

- [ ] Добавить в `config/workspace.php`:
  ```php
  'trusted_organizations' => [],
  ```

### Step 6.2 — Флаг `--recursive` для `workspace:clone`

- [ ] После клонирования: парсить `composer.json` пакета, найти зависимости с vendor из `trusted_organizations`, рекурсивно клонировать.
- [ ] Visited-set для предотвращения циклов.
- [ ] Последовательный (не параллельный) клонирование.

### Step 6.3 — Тесты

- [ ] Тест: `--recursive` клонирует зависимости.
- [ ] Тест: циклические зависимости не вызывают бесконечный цикл.
- [ ] Тест: зависимости вне trusted organizations игнорируются.

---

## Финализация

### Step F.1 — Финальный прогон

- [ ] `vendor/bin/pint --dirty --format agent`
- [ ] `php artisan test --compact` — все тесты проходят.
- [ ] Обновить `README.md` пакета с новыми командами.
- [ ] Обновить `composer.json` версию если нужно.

---

## Порядок выполнения (рекомендация)

```
Фаза 1 (Стабы)       → самостоятельная, без зависимостей
Фаза 2 (Quality Gate) → самостоятельная, без зависимостей
Фаза 3 (Isolated)     → зависит от Фазы 2 (PackageVerifier)
Фаза 4 (Git/Release)  → зависит от Фаз 2 и 3
Фаза 5 (Inventory)    → самостоятельная, без зависимостей
Фаза 6 (Recursive)    → самостоятельная, без зависимостей
```

Фазы 1, 2, 5, 6 можно выполнять параллельно разными агентами.
Фаза 3 → после Фазы 2. Фаза 4 → после Фаз 2 и 3.
