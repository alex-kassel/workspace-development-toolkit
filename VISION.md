# Vision & Strategy: Workspace Development Toolkit

> Стратегический документ с оценкой пакета, идеями расширения, юзкейсами и маркетинговыми ходами.
> Используется для планирования roadmap, написания документации и позиционирования.

---

## Оценка пакета

### Что это

`workspace-development-toolkit` — это **Local Package Orchestrator** для Laravel. Он превращает любое Laravel-приложение в централизованный mission control center для создания, управления и тестирования множества независимых пакетов в едином рабочем пространстве.

### Рыночная ниша

В экосистеме Laravel существует явный пробел:

| Инструмент | Что делает | Чего не делает |
|---|---|---|
| `spatie/laravel-package-tools` | Упрощает *создание* отдельных пакетов | Не управляет рабочим пространством из нескольких пакетов |
| `orchestra/testbench` | Тестирование пакетов в изоляции | Не оркестрирует workspace |
| `symplify/monorepo-builder` | Монорепо для Symfony | Другая философия, не Laravel-native |
| `composer/satis` | Приватный Packagist | Не помогает с локальной разработкой |

**Workspace Development Toolkit** заполняет именно эту нишу: «У меня Laravel-приложение, и я хочу разрабатывать в нём 3-15 пакетов одновременно, без боли.»

### Уникальные конкурентные преимущества

1. **Dual Workspace Architecture** — ни один существующий инструмент не предлагает одновременно Nested (multi-vendor) и Flat (fixed-vendor) парадигмы.
2. **Pre-install Restore** — standalone CLI `php workspace restore` решает критическую проблему CI: Composer path repositories ломаются на fresh checkout.
3. **Directory Aliasing** — пакет `alex-kassel/scraper-core` может жить на диске как `app/Cores/Scraper`, сохраняя canonical Composer identity.
4. **Zero Host Pollution** — пакет не помещает бизнес-логику в хост, не создаёт контроллеры, не модифицирует routes.
5. **Defensive DX** — каждая ошибка = actionable hint с copy-pasteable командой.

### Целевая аудитория

- **Solo-разработчики**, строящие open-source библиотеки
- **Агентства**, управляющие модулями для множества клиентов
- **Enterprise-команды**, практикующие DDD / Modular Monolith
- **AI-powered workflows**, где агенты оркестрируют пакеты через Artisan

---

## 5 советов по расширению функциональности

### 1. `package:diff {name} {--from=tag}`

**Что:** Показать diff между текущим состоянием пакета и последним тегом/релизом.

**Зачем:** При подготовке релиза разработчик хочет увидеть, что изменилось с последней версии. Это основа для написания changelog.

**Реализация:** `git diff {tag}..HEAD --stat` + `git log {tag}..HEAD --oneline` внутри директории пакета.

### 2. `package:deps {name}`

**Что:** Визуализация дерева зависимостей пакета — какие другие локальные пакеты он использует, кто использует его.

**Зачем:** В монорепо с 10+ пакетами крайне важно видеть граф зависимостей. Это помогает планировать порядок релизов и обнаруживать циклические зависимости.

**Реализация:** Парсить `composer.json` каждого пакета, строить граф, выводить как дерево в терминале или как Mermaid-диаграмму.

### 3. `workspace:status`

**Что:** Сводка по всем пакетам всех workspaces: git branch, dirty/clean, ahead/behind remote, installed version.

**Зачем:** Как `git status`, но для всего workspace. Одна команда — полная картина. Незаменимо утром, когда открываешь проект и хочешь понять, где остановился вчера.

**Реализация:** Для каждого пакета: `git status --porcelain`, `git branch --show-current`, `git rev-list @{u}.. --count`, `Composer\InstalledVersions::getVersion()`.

### 4. `package:bump {name} {--major|--minor|--patch}`

**Что:** Семантическое версионирование: обновить `version` в `composer.json`, создать git tag, обновить CHANGELOG.

**Зачем:** Простой, стандартизированный release workflow без ручных правок JSON и создания тегов.

**Реализация:**
1. Прочитать текущую версию из `composer.json`.
2. Инкрементировать major/minor/patch.
3. Обновить `composer.json`.
4. Переместить `[Unreleased]` секцию в CHANGELOG под новую версию.
5. `git add . && git commit -m "Release v{version}"`.
6. `git tag v{version}`.

### 5. `workspace:ci-matrix`

**Что:** Генерация GitHub Actions workflow matrix для тестирования всех пакетов.

**Зачем:** Автоматизация CI-сетапа. Одна команда → готовый `.github/workflows/packages.yml`, который тестирует каждый пакет отдельно, параллельно, с нужной PHP-версией.

**Реализация:** Сканировать workspace, для каждого пакета определить PHP constraint, сгенерировать matrix YAML.

---

## Убийственные юзкейсы

### 🏢 Юзкейс 1: Агентства и Multi-Client Management

**Сценарий:** Digital-агентство обслуживает 5 клиентов. Каждый клиент — отдельный модуль с собственной бизнес-логикой.

```bash
php artisan workspace:register clients/alpha --vendor=alpha-corp
php artisan workspace:register clients/beta --vendor=beta-corp

php artisan package:make payment-gateway --workspace=clients/alpha
php artisan package:make crm-sync --workspace=clients/beta --install
```

**Ценность:** Единый Laravel-хост, изолированные клиентские модули, каждый со своим git-репозиторием. Новый разработчик клонирует хост → `php workspace restore` → все модули на месте.

---

### 🧱 Юзкейс 2: DDD / Modular Monolith

**Сценарий:** E-commerce платформа разбита на домены: Billing, Inventory, Notifications, User Management.

```bash
php artisan workspace:register modules --vendor=my-app --default
php artisan package:make billing --install
php artisan package:make inventory --install
php artisan package:make notifications --install
php artisan package:make user-management --install
```

**Ценность:** Чёткие границы между доменами. Каждый модуль — отдельный Composer-пакет с чётким API. Можно тестировать изолированно. Можно извлечь в отдельный сервис позже.

---

### 🚀 Юзкейс 3: Open-Source Incubator

**Сценарий:** Разработчик строит 3 публичных пакета одновременно, все на стадии MVP.

```bash
php artisan workspace:register packages --default
php artisan package:make my-handle/laravel-cache-warmer --install --dev
php artisan package:make my-handle/laravel-health-check --install --dev
php artisan package:make my-handle/laravel-feature-flags --install --dev

# Перед публикацией:
php artisan package:check my-handle/laravel-cache-warmer --isolated
php artisan package:release-check my-handle/laravel-cache-warmer
```

**Ценность:** Три пакета в одном workspace, единый dev experience. `--isolated` гарантирует, что пакет работает standalone. `release-check` — pre-flight перед публикацией на Packagist.

---

### 👥 Юзкейс 4: Enterprise Team

**Сценарий:** Команда из 5 разработчиков, каждый отвечает за свой пакет. Общий Laravel-хост как staging ground.

```bash
php artisan workspace:list --sync
php artisan package:check --all

# CI/CD:
php artisan package:check --all --isolated
```

**Ценность:** `workspace:list` — мгновенная сводка. `package:check --all` — гарантия качества по всем пакетам. Параллельная проверка через `Process::pool()`.

---

### 🤖 Юзкейс 5: AI-Assisted Development

**Сценарий:** AI-агент получает задачу: «Создай пакет для интеграции с CRM и подключи его».

```bash
# Агент выполняет:
php artisan package:make crm-integration --install --dev
# ... пишет код ...
php artisan package:check crm-integration
php artisan package:release-check crm-integration
```

**Ценность:** AI-агент работает через стандартизированные Artisan-команды, а не через ad-hoc shell-скрипты. Workspace toolkit — это deterministic CLI layer, который агент не может обойти.

---

## Маркетинговые углы

### Tagline варианты

- "Transform your Laravel app into a Package Development Mission Control"
- "The missing monorepo toolkit for Laravel"
- "Build, test, and ship multiple Laravel packages from a single workspace"

### Ключевые сообщения для README / Landing Page

1. **Проблема:** Разработка нескольких Laravel-пакетов одновременно — это ад: ручные composer path repos, gitignore, CI-поломки на fresh checkout.
2. **Решение:** Одна команда для создания пакета. Одна команда для подключения. Одна команда для проверки. Всё автоматизировано.
3. **Отличие:** Единственный Laravel-native toolkit, поддерживающий Flat и Nested workspace парадигмы с pre-install restore для CI.

### SEO / Discoverability теги

- `laravel-package-development`
- `monorepo`
- `workspace-management`
- `laravel-toolkit`
- `package-scaffolding`

### Потенциальные партнёрства / интеграции

- **Laravel Boost (MCP)** — интеграция через dev-dependency suggestion
- **Spatie** — complementary к их `laravel-package-tools`
- **Orchestra Testbench** — нативная поддержка в scaffolding
- **Laravel News / Laracasts** — tutorial potential

---

## Roadmap Ideas (Post-Migration)

| Приоритет | Фича | Описание |
|---|---|---|
| P1 | `package:diff` | Diff между текущим состоянием и последним тегом |
| P1 | `workspace:status` | Git status сводка по всем пакетам |
| P2 | `package:bump` | Semantic versioning workflow |
| P2 | `package:deps` | Dependency graph visualization |
| P3 | `workspace:ci-matrix` | GitHub Actions matrix generation |
| P3 | `--json` output | Machine-readable output для CI/AI |
| P4 | Plugin system | Расширяемость через custom checks/validators |
| P4 | `workspace:dashboard` | Web-based dashboard (Filament?) |
