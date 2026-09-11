# Package Release & Packagist Publication Reference

## 1. Release Modes

| Criteria | Mode A: Initial Public Release | Mode B: Fast-Track "Green Corridor" |
|---|---|---|
| **Trigger** | First time package is published to Packagist | Routine version bumps (`v1.0.1`, `v1.1.0`) |
| **Verification** | `php artisan package:release-check <pkg>` + Clean App test | `php artisan package:release-check <pkg> --fast` (~10s) |
| **Packagist** | Manual submit on Packagist.org + Webhook setup | Automatic sync via GitHub Webhook upon `git push --tags` |

## 2. GitHub Repository Metadata Standard
- **Description**: Exact description matching package `composer.json`.
- **Website / Homepage**: Link to the package page on Packagist (`https://packagist.org/packages/<vendor>/<package>`).
- **Topics**: Curated relevant keywords (e.g. `laravel`, `workspace`, `monorepo`, `composer`, `packages`).
- Apply via GitHub CLI:
  ```bash
  gh repo edit <vendor>/<package> \
    --description "<description from composer.json>" \
    --homepage "https://packagist.org/packages/<vendor>/<package>" \
    --add-topic "laravel" --add-topic "workspace" --add-topic "packages"
  ```

## 3. Strict SemVer, Tag Immutability & Git Hygiene
- **Pull-Before-Tag**: Always run `git pull --rebase origin main` before creating and pushing tags to ensure local workspace is aligned across all machines.
- **Tag Immutability**: All published tags are strictly immutable. Never delete, re-point, or force-push an existing tag.
- Any subsequent hotfix or adjustment must be released under an incremented version tag (`vX.Y.(Z+1)`).
