# Audit System: Design & Implementation Plan

> Трёхуровневая система проверки качества с tamper-proof сертификацией.
> Для пакета `alex-kassel/workspace-development-toolkit`.

---

## Архитектура: Пирамида проверок

```
           ╔═══════════════════╗
           ║   FULL AUDIT      ║  ← Pre-release, сертификат
           ║  package:audit    ║     Несколько минут
           ╠═══════════════════╣
           ║  DEEP CHECK       ║  ← Pre-commit / PR
         ╔═╣  package:check    ║     1-3 минуты
         ║ ╠═══════════════════╣
         ║ ║  QUICK CHECK      ║  ← Во время разработки
         ║ ║  package:check    ║     Секунды
         ╚═╩═══════════════════╝
```

### Принцип вложенности

Каждый уровень **ВКЛЮЧАЕТ** все проверки предыдущего. Это не три отдельных набора — это одна пирамида:

| Уровень | Команда | Включает | Новые проверки | Когда запускать |
|---|---|---|---|---|
| **Quick** | `package:check {name} --quick` | — | Composer validate, Pint `--test` | При каждом изменении кода |
| **Deep** | `package:check {name}` (default) | Quick | + PHPStan Level 8, + PHPUnit/Pest | Перед коммитом, в PR |
| **Audit** | `package:audit {name}` | Deep | + Isolated sandbox, + README, + Export-ignore, + Git cleanliness, + Security scan, + Сертификат | Перед релизом на Packagist |

Таким образом `package:check` **по умолчанию** запускает Deep Check. Флаг `--quick` сокращает до быстрой проверки. А `package:audit` — это отдельная команда, потому что она кардинально отличается по назначению: она не просто проверяет, а **сертифицирует**.

---

## Tamper-Proof сертификация

### Проблема

AI-агент имеет полный доступ к файловой системе и git. Он может:
- Создавать и модифицировать любые файлы
- Делать git commit, tag, notes
- Подменять результаты проверок

**Вывод:** Сделать подделку НЕВОЗМОЖНОЙ нельзя. Но можно сделать подделку **МГНОВЕННО ОБНАРУЖИВАЕМОЙ**.

### Решение: Reproducible Verification

Сертификат содержит достаточно информации, чтобы **любой человек или CI** мог независимо проверить его подлинность за одну команду.

#### Как это работает

**Выдача сертификата (`package:audit`):**
```
1. git rev-parse HEAD          → commit_hash (abc123)
2. git rev-parse HEAD^{tree}   → tree_hash (def456)  // хеш ВСЕГО исходного кода
3. Запустить ВСЕ проверки      → results[] (каждый результат с hash вывода)
4. fingerprint = SHA-256(tree_hash + canonical_json(results))
5. Записать AUDIT.json         → {commit, tree_hash, results, fingerprint, timestamp}
6. git tag audit/v{version}    → тег указывает на commit ПЕРЕД добавлением AUDIT.json
```

**Верификация (`package:audit --verify`):**
```
1. Прочитать AUDIT.json        → {commit, tree_hash, fingerprint, ...}
2. git checkout {commit}       → переключиться на сертифицированный коммит
3. git rev-parse HEAD^{tree}   → пересчитать tree_hash → сравнить
4. Запустить ВСЕ проверки      → пересчитать results
5. Пересчитать fingerprint     → сравнить
6. Если совпадает              → ✅ VERIFIED
7. Если не совпадает           → ❌ FORGED — конкретно указать, что не сходится
```

#### Почему это работает

| Атака | Результат |
|---|---|
| Агент подделал результаты проверок | Перезапуск проверок даёт другие результаты → fingerprint не совпадает |
| Агент изменил код после аудита | `tree_hash` отличается от сертифицированного |
| Агент пересчитал fingerprint | Для пересчёта нужно, чтобы проверки реально прошли |
| Агент подменил AUDIT.json | `git tag audit/v{version}` указывает на оригинальный commit → diff видим |

#### Формат сертификата (`AUDIT.json`)

```json
{
  "schema_version": "1.0.0",
  "package": "alex-kassel/workspace-development-toolkit",
  "version": "0.2.0",
  "audit": {
    "commit": "abc123def456...",
    "tree_hash": "789abc012def...",
    "branch": "main",
    "timestamp": "2026-09-11T16:00:00+02:00",
    "environment": {
      "php": "8.4.1",
      "laravel": "12.8.0",
      "os": "windows"
    }
  },
  "checks": {
    "composer_validate": {
      "status": "passed",
      "output_hash": "sha256:...",
      "duration_seconds": 1.2
    },
    "pint": {
      "status": "passed",
      "output_hash": "sha256:...",
      "duration_seconds": 3.5
    },
    "phpstan": {
      "status": "passed",
      "level": 8,
      "output_hash": "sha256:...",
      "duration_seconds": 8.1
    },
    "tests": {
      "status": "passed",
      "tests_count": 85,
      "assertions_count": 320,
      "output_hash": "sha256:...",
      "duration_seconds": 12.4
    },
    "isolated": {
      "status": "passed",
      "output_hash": "sha256:...",
      "duration_seconds": 45.0
    },
    "readme": {
      "status": "passed",
      "rules_passed": 5,
      "rules_total": 5
    },
    "export_ignore": {
      "status": "passed"
    },
    "git_cleanliness": {
      "status": "passed",
      "branch": "main",
      "has_remote": true
    }
  },
  "verdict": "PASSED",
  "fingerprint": "sha256:a1b2c3d4e5f6..."
}
```

> Формат — JSON, не Markdown. Это принципиально: JSON парсится программно и надёжно. Markdown regex-парсинг — путь к хрупкости и подделкам.

#### Дополнительные усиления (опционально)

| Метод | Что даёт | Сложность |
|---|---|---|
| **Git signed tag** (`git tag -s`) | Требует GPG-ключ человека; агент не может подписать | Низкая, но требует GPG setup |
| **GitHub Release** | Опубликовать fingerprint как release asset; неизменяем через git | Средняя, нужен GitHub API |
| **SHA в README badge** | Визуальный индикатор; ссылка на верификацию | Низкая |

---

## Взаимосвязь с `laravel-package-audit`

### Текущее состояние

`laravel-package-audit` — это **skill-фреймворк** (инструкции для AI-агентов), а не runtime-пакет. Он содержит:
- 7 контрактов аудита (architecture, code quality, database, security, composer, testing, consumer release)
- JSON-схемы для отчётов
- Шаблоны для `RELEASE-GATE.md`
- 2-фазный lifecycle с human gate

### Рекомендация

Разделить ответственность:

| Компонент | Где живёт | Роль |
|---|---|---|
| **Автоматические проверки** (Pint, PHPStan, tests, isolated, README) | `workspace-development-toolkit` | CLI-команды `package:check`, `package:audit` |
| **Agent skill** (workflow, human gate, контракты) | `laravel-package-audit` | Инструкции, как AI-агент должен проводить аудит |
| **Audit contracts** (7 доменных проверок) | `laravel-package-audit` | Чеклисты для ручной/агентской инспекции |

`workspace-development-toolkit` предоставляет **инструменты** (команды). `laravel-package-audit` предоставляет **процесс** (skill + контракты). Они комплементарны.

При этом `package:audit` должен быть самодостаточным — он запускает все автоматизируемые проверки и выдаёт сертификат без участия `laravel-package-audit`. А skill из `laravel-package-audit` может использовать `package:audit` как один из своих шагов, добавляя сверху ручные/агентские инспекции (архитектура, безопасность, API stability).

---

## Чеклист реализации

### Фаза A: Рефакторинг тиров в `PackageVerifier`

- [ ] **Step A.1** — Добавить поддержку уровней в `PackageVerifier`:
  - Метод `check(string $packagePath, string $tier = 'deep', array $options = []): array<CheckResult>`
  - `'quick'` — Composer validate + Pint
  - `'deep'` (default) — Quick + PHPStan + Tests
  - Конфигурация тиров через `config/workspace.php`
- [ ] **Step A.2** — Обновить `PackageCheckCommand`:
  - Добавить `--quick` флаг
  - Default (без флагов) = deep check
  - `--isolated` по-прежнему добавляет isolated verification поверх deep
  - `--only` по-прежнему работает для cherry-picking
- [ ] **Step A.3** — Тесты:
  - `package:check --quick` запускает только Composer + Pint
  - `package:check` (default) запускает все 4 проверки
  - Вложенность: deep включает все проверки quick

---

### Фаза B: Сервис `PackageAuditor`

- [ ] **Step B.1** — Создать `src/Services/PackageAuditor.php`:
  ```
  class PackageAuditor
  {
      public function __construct(
          private PackageVerifier $verifier,
          private IsolatedPackageVerifier $isolatedVerifier,
          private ReadmeValidator $readmeValidator,
          private ReleaseChecker $releaseChecker,
          private GitInspector $gitInspector,
      ) {}
  
      /** Запустить полный аудит и вернуть структурированный результат */
      public function audit(string $packagePath): AuditReport;
  
      /** Верифицировать существующий сертификат */
      public function verify(string $packagePath): VerificationResult;
  
      /** Вычислить fingerprint из tree_hash и результатов */
      protected function computeFingerprint(string $treeHash, array $checks): string;
  
      /** Получить tree_hash текущего коммита */
      protected function getTreeHash(string $packagePath): string;
  }
  ```
- [ ] **Step B.2** — Создать `src/DTOs/AuditReport.php`:
  ```
  final readonly class AuditReport
  {
      public function __construct(
          public string $package,
          public string $version,
          public string $commit,
          public string $treeHash,
          public string $branch,
          public string $timestamp,
          public array $environment,      // php, laravel, os
          public array $checks,           // array<string, CheckResult>
          public string $verdict,          // 'PASSED', 'FAILED'
          public string $fingerprint,
      ) {}
  
      public function toJson(): string;
      public function allPassed(): bool;
  }
  ```
- [ ] **Step B.3** — Создать `src/DTOs/VerificationResult.php`:
  ```
  final readonly class VerificationResult
  {
      public function __construct(
          public bool $verified,
          public string $status,           // 'VERIFIED', 'FORGED', 'OUTDATED', 'MISSING'
          public ?string $reason,          // конкретная причина несоответствия
          public ?AuditReport $certificate,
      ) {}
  }
  ```

---

### Фаза C: Fingerprint Engine

- [ ] **Step C.1** — Реализовать `computeFingerprint()`:
  ```php
  protected function computeFingerprint(string $treeHash, array $checks): string
  {
      // Каноническая сортировка: ключи проверок в алфавитном порядке
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
  ```
- [ ] **Step C.2** — Реализовать `getTreeHash()`:
  ```php
  protected function getTreeHash(string $packagePath): string
  {
      $result = Process::path($packagePath)->run(['git', 'rev-parse', 'HEAD^{tree}']);
      return trim($result->output());
  }
  ```
- [ ] **Step C.3** — Тесты fingerprint:
  - Одинаковые входные данные → одинаковый fingerprint (детерминизм)
  - Изменение tree_hash → другой fingerprint
  - Изменение результата проверки → другой fingerprint
  - Порядок проверок не влияет (ksort)

---

### Фаза D: Команда `PackageAuditCommand`

- [ ] **Step D.1** — Создать `src/Commands/PackageAuditCommand.php`:
  - Signature: `package:audit {name} {--verify} {--json}`
  - Без `--verify`: запускает полный аудит, генерирует `AUDIT.json`, создаёт git tag
  - С `--verify`: верифицирует существующий `AUDIT.json`
  - С `--json`: машиночитаемый вывод (для CI)
- [ ] **Step D.2** — Workflow аудита:
  ```
  1. Резолвить пакет через PackageResolver
  2. Проверить: git repo exists, working tree clean
  3. Запустить Deep Check (Composer, Pint, PHPStan, Tests)
  4. Запустить Isolated Verification
  5. Запустить README validation
  6. Проверить .gitattributes export-ignore
  7. Собрать tree_hash и все результаты
  8. Вычислить fingerprint
  9. Записать AUDIT.json в корень пакета
  10. Создать git tag: audit/v{version} → HEAD (commit ДО AUDIT.json)
  11. git add AUDIT.json && git commit -m "Audit certificate for v{version}"
  ```
- [ ] **Step D.3** — Workflow верификации:
  ```
  1. Прочитать AUDIT.json
  2. git stash (если dirty)
  3. git checkout {certified_commit}
  4. Проверить tree_hash: git rev-parse HEAD^{tree} == audit.tree_hash
  5. Перезапустить ВСЕ проверки
  6. Пересчитать fingerprint
  7. Сравнить fingerprint с сертифицированным
  8. git checkout {original_branch}
  9. git stash pop (если было)
  10. Вывести результат: VERIFIED / FORGED / OUTDATED
  ```
- [ ] **Step D.4** — Зарегистрировать в ServiceProvider

---

### Фаза E: Тесты для Audit System

- [ ] **Step E.1** — Unit тесты `PackageAuditor`:
  - Аудит пакета с passing проверками → PASSED verdict, valid fingerprint
  - Аудит пакета с failing проверкой → FAILED verdict, no certificate
  - Аудит пакета с dirty git → отказ с actionable error
- [ ] **Step E.2** — Unit тесты верификации:
  - Валидный сертификат → VERIFIED
  - Изменённый fingerprint → FORGED
  - Отсутствующий AUDIT.json → MISSING
  - Новые коммиты после аудита → OUTDATED
- [ ] **Step E.3** — Feature тесты `PackageAuditCommand`:
  - `package:audit vendor/pkg` → создаёт AUDIT.json
  - `package:audit vendor/pkg --verify` → проверяет сертификат
  - `package:audit vendor/pkg --json` → JSON output

---

### Фаза F: Интеграция с `laravel-package-audit`

- [ ] **Step F.1** — Обновить `SKILL.md` в `laravel-package-audit`:
  - Phase 1: Агент запускает `package:audit {name}` (read-only анализ)
  - Hard Stop: Агент показывает результат, ждёт одобрения
  - Phase 2: Агент фиксит проблемы, перезапускает `package:audit`
  - При успехе: `package:audit` выдаёт сертификат автоматически
- [ ] **Step F.2** — Обновить контракты в `laravel-package-audit`:
  - Удалить дублирующие автоматические проверки (Pint, PHPStan, tests) — они теперь в `workspace-development-toolkit`
  - Оставить только то, что требует человеческого суждения: architecture review, security reasoning, API stability assessment
- [ ] **Step F.3** — Обновить шаблоны:
  - Заменить `RELEASE-GATE.md` template на `AUDIT.json` schema
  - Удалить markdown-based сертификат

---

### Фаза G: README Badge и публичная верификация

- [ ] **Step G.1** — После успешного аудита автоматически генерировать badge URL:
  ```
  ![Audit](https://img.shields.io/badge/Audit-Verified-10b981?style=flat-square)
  ```
  С линком на `AUDIT.json` в репозитории.
- [ ] **Step G.2** — Документация: как верифицировать сертификат:
  ```bash
  # Клонировать пакет
  git clone https://github.com/vendor/package.git
  cd package
  
  # Установить workspace toolkit
  composer require alex-kassel/workspace-development-toolkit --dev
  
  # Верифицировать сертификат
  php artisan package:audit vendor/package --verify
  ```

---

## Порядок выполнения

```
Фаза A (Рефакторинг тиров)  → самостоятельная
Фаза B (PackageAuditor)      → самостоятельная
Фаза C (Fingerprint)         → самостоятельная
Фаза D (Команда)             → зависит от A, B, C
Фаза E (Тесты)               → зависит от D
Фаза F (laravel-package-audit) → зависит от D
Фаза G (Badge/docs)          → зависит от D
```

Фазы A, B, C можно выполнять параллельно.
Фаза D — после завершения A, B, C.
Фазы E, F, G — после D.

---

## Открытые вопросы (для обсуждения с владельцем)

1. **Git tag naming**: `audit/v{version}` или `audit/{version}-{short_hash}`?
2. **AUDIT.json location**: Корень пакета или `.audit/AUDIT.json`?
3. **GPG signing**: Добавлять ли `--sign` опцию для `package:audit` (требует GPG setup)?
4. **CI integration**: Добавлять ли автоматический GitHub Actions workflow?
5. **Multiple audits**: Хранить ли историю аудитов (`.audit/history/`) или только последний?
