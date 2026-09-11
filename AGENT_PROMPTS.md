# Agent Orchestration: Audit System Implementation

> Готовые промпты для агентов. Копируй и вставляй по порядку.
> Каждый промпт — один агент, одна задача.

---

## Порядок выполнения

```
Prompt 1 → Создать laravel-package-audit как настоящий Laravel-пакет
Prompt 2 → Добавить тиры (quick/deep) в workspace-development-toolkit
Prompt 3 → Полный аудит workspace-development-toolkit
```

Prompt 1 и Prompt 2 независимы — можно запускать в любом порядке или параллельно.
Prompt 3 — после 1 и 2.

---

## Prompt 1: Создание `laravel-package-audit`

```
Твоя задача: превратить пакет `laravel-package-audit` из skill-фреймворка в полноценный самодостаточный Laravel-пакет с CLI-командами для аудита и сертификации любых Laravel/PHP-пакетов.

## Расположение

Пакет уже существует и установлен локально:
- Путь: `packages/alex-kassel/laravel-package-audit`
- Если его нет — клонируй: `php artisan workspace:clone alex-kassel/laravel-package-audit --install --dev`
- GitHub: https://github.com/alex-kassel/laravel-package-audit

## Важно

- Пакет должен быть САМОДОСТАТОЧНЫМ — работать без workspace-development-toolkit.
- Пакет НЕ зависит от workspace-development-toolkit. Он может быть установлен в любой Laravel-проект.
- НЕ добавлять phpunit, phpstan, pint в зависимости пакета. Пакет проверяет наличие бинарников в vendor/bin/ хоста.
- Следуй конвенциям Laravel-пакетов: ServiceProvider, config, commands.
- Запусти vendor/bin/pint --dirty --format agent после изменений PHP.

## Что создать

### 1. Структура пакета

Убедись, что composer.json корректный:
- name: alex-kassel/laravel-package-audit
- require: illuminate/support ^11.0|^12.0|^13.0, illuminate/console ^11.0|^12.0|^13.0
- require-dev: orchestra/testbench, phpunit/phpunit
- autoload: AlexKassel\PackageAudit\ → src/
- extra.laravel.providers: ServiceProvider

### 2. Config файл: config/package-audit.php

```php
return [
    'checks' => [
        'composer_validate' => true,
        'pint' => true,
        'phpstan' => ['enabled' => true, 'level' => 8],
        'tests' => true,
        'isolated' => true,
        'readme' => ['enabled' => true, 'required_sections' => ['Requirements', 'Installation', 'Usage', 'Testing', 'License']],
        'export_ignore' => true,
        'git_cleanliness' => true,
    ],
    'certificate_filename' => 'AUDIT.json',
];
```

### 3. DTOs

Создай `src/DTOs/CheckResult.php`:
```php
final readonly class CheckResult
{
    public function __construct(
        public string $check,           // 'composer', 'pint', 'phpstan', 'tests', 'isolated', 'readme', 'export_ignore', 'git'
        public string $status,           // 'passed', 'failed', 'skipped'
        public string $output,
        public float $durationSeconds,
    ) {}
}
```

Создай `src/DTOs/AuditReport.php`:
```php
final readonly class AuditReport
{
    public function __construct(
        public string $package,
        public string $version,
        public string $commit,
        public string $treeHash,
        public string $branch,
        public string $timestamp,
        public array $environment,       // ['php' => '8.4', 'laravel' => '12.x', 'os' => 'windows']
        public array $checks,            // array<string, CheckResult>
        public string $verdict,           // 'PASSED', 'FAILED'
        public string $fingerprint,
        public string $auditorVersion,   // version of laravel-package-audit itself
    ) {}

    public function toArray(): array;
    public function toJson(): string;
    public function allPassed(): bool;
}
```

Создай `src/DTOs/VerificationResult.php`:
```php
final readonly class VerificationResult
{
    public function __construct(
        public bool $verified,
        public string $status,           // 'VERIFIED', 'FORGED', 'OUTDATED', 'MISSING'
        public ?string $reason,
    ) {}
}
```

### 4. Сервисы

#### `src/Services/AuditRunner.php`
Основной сервис. Запускает все проверки и генерирует AuditReport.

Проверки (в этом порядке):
1. Git cleanliness: `git status --porcelain` (должен быть чистый), `git rev-parse HEAD`
2. Composer validate: `composer validate --strict` в директории пакета
3. Pint: `vendor/bin/pint --test` (бинарник из хоста vendor/bin/)
4. PHPStan: `vendor/bin/phpstan analyse` (используя phpstan.neon пакета)
5. Tests: `vendor/bin/phpunit` или `vendor/bin/pest` (используя phpunit.xml пакета)
6. Isolated verification: экспорт через `git ls-files`, composer install в temp dir, запуск тестов (алгоритм описан ниже)
7. README: проверка наличия файла, заголовка, обязательных секций
8. Export-ignore: проверка .gitattributes

Для каждой проверки: если бинарник не найден → status='skipped', output='Binary not found: vendor/bin/pint. Install it via composer require --dev laravel/pint'.

Алгоритм Isolated Verification:
1. Экспортировать файлы: `git ls-files --cached --others --exclude-standard -z` → скопировать в sys_get_temp_dir()/package-audit-{random}/
2. Проверить: composer.json НЕ содержит path repositories
3. Требовать: phpunit.xml или phpunit.xml.dist существует
4. Env: COMPOSER_HOME=<temp>, COMPOSER_VENDOR_DIR=<temp>/vendor
5. Маппить COMPOSER_CACHE_DIR из хоста: Windows=%LOCALAPPDATA%/Composer, Linux=~/.cache/composer, macOS=~/Library/Caches/composer
6. Testing env: APP_ENV=testing, CACHE_STORE=array, SESSION_DRIVER=array, QUEUE_CONNECTION=sync, MAIL_MAILER=array
7. Запустить: composer install --prefer-dist --no-interaction --no-progress
8. Запустить: vendor/bin/phpunit --fail-on-empty-test-suite
9. Cleanup в finally block

#### `src/Services/FingerprintCalculator.php`
```php
class FingerprintCalculator
{
    public function compute(string $treeHash, array $checks): string
    {
        ksort($checks);
        $canonical = json_encode([
            'tree_hash' => $treeHash,
            'checks' => array_map(fn (CheckResult $r) => [
                'check' => $r->check,
                'status' => $r->status,
                'output_hash' => hash('sha256', $r->output),
            ], $checks),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'sha256:' . hash('sha256', $canonical);
    }

    public function getTreeHash(string $packagePath): string
    {
        $result = Process::path($packagePath)->run(['git', 'rev-parse', 'HEAD^{tree}']);
        return trim($result->output());
    }
}
```

#### `src/Services/CertificateVerifier.php`
Верифицирует существующий AUDIT.json:
1. Прочитать AUDIT.json
2. Проверить tree_hash: `git rev-parse {commit}^{tree}` == certificate.tree_hash
3. Checkout certified commit (git stash if needed)
4. Перезапустить ВСЕ проверки
5. Пересчитать fingerprint через FingerprintCalculator
6. Сравнить с сертифицированным fingerprint
7. Вернуть checkout в исходное состояние
8. Вернуть VerificationResult: VERIFIED/FORGED/OUTDATED/MISSING

### 5. Команды

#### `src/Commands/AuditCommand.php`
Signature: `package:audit {path} {--verify} {--json}`

Без --verify:
1. Резолвить путь (argument = путь к директории пакета)
2. Запустить AuditRunner->audit($path)
3. Если verdict=PASSED: записать AUDIT.json в корень пакета
4. Создать git tag: `audit/v{version}` → HEAD (текущий commit, ДО записи AUDIT.json)
5. git add AUDIT.json && git commit -m "Audit certificate for v{version}"
6. Вывести красивый отчёт в терминал

С --verify:
1. Запустить CertificateVerifier->verify($path)
2. Вывести результат: VERIFIED / FORGED / OUTDATED / MISSING

С --json:
1. Вывести AuditReport или VerificationResult как JSON

### 6. ServiceProvider

Зарегистрировать:
- AuditRunner, FingerprintCalculator, CertificateVerifier как singletons
- AuditCommand
- Config merge и publishes

### 7. CI Stub

Создай `stubs/github-audit-workflow.yml.stub` — publishable через vendor:publish --tag=package-audit-stubs:

```yaml
name: Package Audit
on:
  push:
    tags: ['v*']
jobs:
  audit:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
      - run: composer install --prefer-dist --no-interaction
      - run: php artisan package:audit . --json
```

### 8. Сохрани существующие skills

Все существующие файлы в `resources/`, `references/`, `SKILL.md` — СОХРАНИ. Они продолжают работать как agent skill. Просто теперь пакет ДОПОЛНИТЕЛЬНО имеет CLI-команды.

### 9. Тесты

Создай тесты:
- AuditRunner: audit пакета с passing/failing проверками
- FingerprintCalculator: детерминизм, порядок не влияет
- CertificateVerifier: VERIFIED/FORGED/MISSING
- AuditCommand: feature test с Process::fake()

### 10. README.md

Обнови README.md пакета:
- Что это: самодостаточный аудитор для Laravel-пакетов с tamper-proof сертификацией
- Установка: composer require --dev alex-kassel/laravel-package-audit
- Использование: php artisan package:audit /path/to/package
- Верификация: php artisan package:audit /path/to/package --verify
- Как работает fingerprint (кратко)
- Как проверить сертификат (для потребителей пакета)

### Отчёт

После завершения дай мне:
1. Список всех созданных/изменённых файлов
2. Результат запуска тестов
3. Результат vendor/bin/pint --dirty --format agent
4. Любые проблемы или решения, которые ты принял
```

---

## Prompt 2: Тиры проверок в `workspace-development-toolkit`

```
Твоя задача: добавить поддержку тиров (quick/deep) в команду package:check пакета workspace-development-toolkit.

## Расположение

Пакет: packages/alex-kassel/workspace-development-toolkit

## Что сделать

### 1. Обновить PackageCheckCommand

Текущая команда: `package:check {name?} {--all} {--fix} {--only=} {--isolated}`

Добавить флаг `--quick`:
- `package:check my-package --quick` → только Composer validate + Pint --test (секунды)
- `package:check my-package` (без флагов) → Composer + Pint + PHPStan + Tests (deep, default)
- `package:check my-package --isolated` → deep + isolated verification

### 2. Обновить PackageVerifier

Добавить параметр tier в метод checkAll:

```php
public function checkAll(string $packagePath, string $tier = 'deep', array $only = [], bool $fix = false): array
{
    $checks = match ($tier) {
        'quick' => ['composer', 'pint'],
        'deep' => ['composer', 'pint', 'phpstan', 'tests'],
        default => ['composer', 'pint', 'phpstan', 'tests'],
    };

    if ($only !== []) {
        $checks = array_intersect($checks, $only);
    }

    // ... запустить только выбранные проверки
}
```

### 3. Обновить конфигурацию

В config/workspace.php проверь, что секция quality_checks уже есть. Если нет — добавь.

### 4. Тесты

- Тест: `package:check --quick` запускает только Composer + Pint
- Тест: `package:check` (default) запускает все 4
- Тест: `--quick` и `--only` работают вместе корректно

### 5. Финализация

- Запусти vendor/bin/pint --dirty --format agent
- Запусти php artisan test packages/alex-kassel/workspace-development-toolkit/tests --compact
- Обнови README.md пакета: добавь описание --quick флага в Command Reference

### Отчёт

Дай мне:
1. Список изменённых файлов
2. Результат тестов
3. Результат Pint
```

---

## Prompt 3: Аудит пакета workspace-development-toolkit

```
Твоя задача: провести полный аудит пакета workspace-development-toolkit и убедиться, что он готов к публикации.

## Расположение

Пакет: packages/alex-kassel/workspace-development-toolkit

## Пошаговый план

### Шаг 1: Проверка структуры

1. Прочитай composer.json — проверь корректность name, autoload, dependencies, extra.laravel
2. Прочитай config/workspace.php — проверь все опции
3. Проверь ServiceProvider — все сервисы зарегистрированы как singletons? Все команды зарегистрированы?
4. Проверь, что все классы в src/ имеют declare(strict_types=1)
5. Проверь, что все методы имеют type hints и return types

### Шаг 2: Запуск автоматических проверок

Запусти по очереди и запиши результат каждой:

```bash
# 1. Composer validate
cd packages/alex-kassel/workspace-development-toolkit && composer validate --strict

# 2. Pint
vendor/bin/pint --test packages/alex-kassel/workspace-development-toolkit

# 3. PHPStan (если phpstan.neon есть в пакете)
vendor/bin/phpstan analyse packages/alex-kassel/workspace-development-toolkit/src --level=8

# 4. Tests
php artisan test packages/alex-kassel/workspace-development-toolkit/tests --compact
```

### Шаг 3: Исправление проблем

Если какие-то проверки упали — исправь. После исправлений перезапусти проверку.

### Шаг 4: README review

Прочитай packages/alex-kassel/workspace-development-toolkit/README.md:
1. Все команды из src/Commands/ описаны?
2. Нет placeholder'ов типа <vendor>/<package>?
3. Есть секции: Requirements, Installation, Usage, Testing, License?
4. Если README устарел (не описывает новые команды package:check, package:readme, package:release-check) — обнови его.

### Шаг 5: Export-ignore

Проверь .gitattributes в пакете. Должен содержать:
```
* text=auto eol=lf
/tests export-ignore
/phpunit.xml.dist export-ignore
/.gitignore export-ignore
/.gitattributes export-ignore
```

Если файла нет — создай.

### Шаг 6: Проверка тестового покрытия

Просмотри все команды в src/Commands/ и все сервисы в src/Services/. 
Для каждого — есть ли тест? Если какой-то ключевой функционал не покрыт тестами — напиши тест.

Минимум: каждая команда должна иметь хотя бы один happy path тест и один error path тест.

### Шаг 7: Финализация

```bash
vendor/bin/pint --dirty --format agent
php artisan test packages/alex-kassel/workspace-development-toolkit/tests --compact
```

### Отчёт

Дай мне подробный отчёт:
1. Результат каждой проверки (passed/failed + output)
2. Что было исправлено
3. Какие тесты были добавлены
4. Финальные результаты всех проверок
5. Общий вердикт: READY / NEEDS_WORK (и что именно)
```

---

## Что делать с отчётами

После получения отчёта от каждого агента:

1. **Prompt 1 отчёт** → Если есть проблемы, дай мне, я скажу как исправить. Если всё ок — переходи к Prompt 3.
2. **Prompt 2 отчёт** → Аналогично. Если тесты проходят — готово.
3. **Prompt 3 отчёт** → Это финальный аудит. Если вердикт READY — пакет готов к публикации.

Если хочешь, отчёты можно вставить в новый чат со мной — я проанализирую и скажу следующие шаги.
