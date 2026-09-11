# Package README Badge Palette & Sections Reference

## 1. Canonical Badge Palette Sequence

`Green (Audit) → Orange (Version) → Red (Laravel) → Indigo (PHP) → Purple (PHPStan)`

| Semantic Purpose | Color Family | HEX Code | Badge Markdown | Link Destination |
|---|---|:---:|---|---|
| **Audit Verification** | Emerald Green | `#10b981` | `[![Audit Verified](https://img.shields.io/badge/Audit-Verified-10b981?logo=shield)](AUDIT.json)` | `AUDIT.json` (or `RELEASE-GATE.md`) |
| **Package Version** | Amber / Orange | `#f59e0b` | `[![Latest Version](https://img.shields.io/packagist/v/<vendor>/<pkg>?color=f59e0b&logo=packagist&logoColor=white)](https://packagist.org/packages/<vendor>/<pkg>)` | Packagist |
| **Laravel Support** | Laravel Red | `#ff2d20` | `[![Laravel](https://img.shields.io/badge/Laravel-11%20%7C%2012%20%7C%2013-ff2d20?logo=laravel&logoColor=white)](https://laravel.com)` | Laravel.com |
| **PHP Runtime** | PHP Indigo | `#777bb4` | `[![PHP](https://img.shields.io/badge/PHP-8.2+-777bb4?logo=php&logoColor=white)](https://php.net)` | PHP.net |
| **Static Analysis** | Purple | `#8b5cf6` | `[![PHPStan](https://img.shields.io/badge/PHPStan-Level%208-8b5cf6?logo=php&logoColor=white)](phpstan.neon.dist)` | `phpstan.neon.dist` |

> [!IMPORTANT]
> - **Audit Badge Rule**: If `AUDIT.json` or `RELEASE-GATE.md` exists, `Audit Verified` MUST be the very first badge. If neither exists, omit this badge completely.
> - **No Double Pipes**: Never use raw Composer double pipes `||` in README badges or prose. Use single pipe `|` or comma list.

## 2. Canonical Copy-Paste Skeleton

```markdown
<h1 align="center">📦 Package Name</h1>

<p align="center">
  <strong>One-sentence clear summary of what this package accomplishes</strong>
</p>

<p align="center">
  <a href="#installation">Installation</a> •
  <a href="#usage">Usage</a> •
  <a href="#testing">Testing</a> •
  <a href="CHANGELOG.md">Changelog</a>
</p>

<p align="center">
  <a href="https://packagist.org/packages/<vendor>/<package>"><img src="https://img.shields.io/packagist/v/<vendor>/<package>?color=f59e0b&logo=packagist&logoColor=white" alt="Latest Version"></a>
  <a href="https://laravel.com"><img src="https://img.shields.io/badge/Laravel-11%20%7C%2012%20%7C%2013-ff2d20?logo=laravel&logoColor=white" alt="Laravel Support"></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/PHP-8.2+-777bb4?logo=php&logoColor=white" alt="PHP Support"></a>
  <a href="phpstan.neon"><img src="https://img.shields.io/badge/PHPStan-Level%208-8b5cf6?logo=php&logoColor=white" alt="PHPStan Level 8"></a>
</p>

---

## Key Features

* **Feature One:** Description.
* **Feature Two:** Description.

---

## Requirements

* **PHP:** 8.2+ (tested on 8.2, 8.3, 8.4)
* **Laravel Framework:** 11.x | 12.x | 13.x

---

## Installation

```bash
composer require <vendor>/<package>
```

---

## Usage

```php
use Vendor\Package\FacadeOrClass;

// Code example
```

---

## Testing

```bash
php artisan test -c packages/<vendor>/<package>/phpunit.xml
```

---

## Changelog

Please see [CHANGELOG.md](CHANGELOG.md) for more information on what has changed recently.

## Security Vulnerabilities

Please review [Security Policies](https://github.com/<vendor>/<package>/security/policy) on how to report vulnerabilities.

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
```
