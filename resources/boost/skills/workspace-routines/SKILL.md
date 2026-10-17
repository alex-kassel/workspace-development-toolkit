---
name: workspace-routines
origin: alex-kassel/workspace-development-toolkit
version: 1.0.0
status: published
description: >-
  Use this skill when the user wants to check, push, pull, or synchronize all local workspace packages and
  the host application at once — e.g. "check all packages", "are my packages pushed?", "sync everything",
  "prepare to switch machines", "continue on another machine", "update all packages", "workspace:check",
  "workspace:push", "workspace:pull". Also use it before ending a working session and after resuming on a
  different machine, because it encodes the restore-first recovery order and the safety rules for git.
---

# Workspace Git Routines

This skill covers the machine-to-machine workflow of the host application and its workspace packages.

## Mental model

* The host application is one git repository. It owns `workspace.json`, `composer.json`, `composer.lock`
  and the generated `workspace` runner.
* Every workspace package under `packages/<vendor>/<package>` is its own git repository.
* `packages/` is Git-ignored by the host; the host repository only stores the recipe.
* The routine code lives in `alex-kassel/workspace-development-toolkit`, **not** in the host application.
  The host only receives generated artifacts: the `workspace` runner, composer shortcuts and agent skills.
  Never implement or patch the routine inside the host; change the toolkit and let it materialize.

## Canonical commands

| Goal | Command |
| --- | --- |
| Read-only preflight, strict exit code | `composer workspace:check` |
| Push committed work and tags | `composer workspace:push` |
| Adopt latest state on another machine | `composer workspace:pull` |
| Same, with flags / machine-readable | `php workspace check\|push\|pull ...` |
| Direct artisan commands (only if environment is healthy) | `php artisan workspace:check\|push\|pull` |

`composer workspace:*` and `php workspace *` go through the standalone runner. Use them as the default
entry points, especially `pull`. The artisan commands are the implementation and are safe to use directly
only when every package directory exists.

## Restore-first rule (most important)

If a package directory is missing, Laravel cannot boot: Composer's autoloader points at classes inside the
missing package and `php artisan` crashes with `Failed to open stream` / `Class not found`. Therefore:

* Use `composer workspace:pull` or `php workspace pull` when packages may be missing.
* The standalone runner runs `workspace_missing_packages()` first, restores missing package directories
  **without booting Laravel**, and only then delegates to `php artisan workspace:pull`.
* Do **not** reach for `php artisan workspace:pull`, `php artisan workspace:check` or `composer install` as
  the first recovery step on a machine with missing packages; use the runner.
* `php workspace check` also validates physical package presence before delegating, so it reports a clean
  "Missing workspace packages" error instead of a fatal autoload error.
* Never delete or rename package directories to "reset" them. `workspace:pull` restores missing ones.

## Semantics

### `workspace:check`

* Checks the host repository and every package.
* **Errors (exit 1):** dirty working tree, missing package, not a git repository, detached HEAD, missing
  `origin`, unpublished branch, ahead of upstream, diverged.
* **Warnings (exit 0):** behind upstream, stashes, tags not pushed.
* Options: `--json`, `--fetch`, `--no-host`, `--workspace=`.
* Never changes git state. (It may fetch with `--fetch`, which only updates remote-tracking refs.)

### `workspace:push`

* Pushes commits and tags of clean repositories; packages first, host last.
* Never commits, merges or force-pushes. Dirty / detached / diverged / missing-remote repositories are
  reported and skipped; the command exits non-zero when anything could not be synchronized.
* Branches without an upstream are pushed with `--set-upstream`.
* Tags are pushed with `--follow-tags`; tags still missing afterwards are pushed explicitly.
* Options: `--dry-run`, `--json`, `--no-host`, `--no-tags`.
* If a package has uncommitted changes, do not try to auto-commit it. Show the file list and let the human
  decide. Never commit on the user's behalf during a routine.

### `workspace:pull`

* Incremental and idempotent:
  1. restore packages that are missing on disk (runner only, before Laravel boots);
  2. fast-forward the host repository when it is behind;
  3. fast-forward every existing package; up-to-date and clean packages are untouched;
  4. run `composer install` only when `composer.json` / `composer.lock` changed or `vendor/` is missing;
  5. run migrations only when migration files changed (or `--migrate` is passed);
  6. install/build front-end assets only when `package.json` / `package-lock.json` changed (or `--assets`);
  7. clear application caches.
* Dirty packages are skipped and reported; they are never overwritten.
* Fast-forward only. Diverged branches require manual resolution.
* Options: `--dry-run`, `--json`, `--no-host`, `--no-install`, `--no-migrate`, `--no-assets`, `--migrate`,
  `--assets`, `--workspace=`.
* A host that is not a git repository is skipped with a warning (relevant for a fresh `create-project`,
  where VCS metadata is stripped).

## Safety rules

1. Never `git reset --hard`, `git clean`, force-push, or delete package directories as part of a routine.
2. Never auto-commit dirty work. Report and stop; the human commits.
3. Never run a routine against production. These commands are for local development environments.
4. Do not hand-edit the composer shortcuts `workspace:check|push|pull` or the root `workspace` runner:
   both are generated by the toolkit (`ComposerManager`, `PublishWorkspaceRunnerAction`/stubs). Change the
   toolkit, then run `php artisan workspace:sync` in the host.
5. Use `--no-host` only deliberately. The host repository carries `workspace.json`, `composer.lock` and the
   generated runner, so it must be clean and pushed for another machine to reproduce the environment.

## Typical flows

### Before shutting down

```bash
composer workspace:check   # must exit 0; warnings are acceptable but read them
composer workspace:push    # commits + tags; never commits for you
```

If `check` reports dirty repositories, commit them yourself (or stash/discard intentionally), then rerun.

### On another machine (host exists, some packages missing or outdated)

```bash
composer workspace:pull
composer workspace:check
```

`pull` restores missing packages, fast-forwards outdated ones, and refreshes dependencies only when needed.
Finish by verifying `check` exits 0.

### One-time upgrade of a machine created before the routines existed

The shortcuts do not exist on such a machine yet:

```bash
git pull
git -C packages/alex-kassel/workspace-development-toolkit pull
composer install
php artisan workspace:sync
```

After that, `composer workspace:pull` etc. are available.

## Creating a new machine

`composer create-project` resolves the latest **stable tag** of the host template. Tags are created manually
after significant template changes; a fresh machine always starts from a tagged state. If unsure which
version was used, inspect the host repository tags. If the lock file references a toolkit commit that is
older than toolkit `main`, that is normally harmless for path packages, but `workspace:sync` and a routine
run should be executed after the first boot.

## Where the implementation lives

* `src/Commands/WorkspaceCheckCommand.php`
* `src/Commands/WorkspacePushCommand.php`
* `src/Commands/WorkspacePullCommand.php`
* `src/Services/WorkspaceGitService.php`
* `src/DTOs/GitRepositoryState.php`, `src/DTOs/GitOperationResult.php`
* `src/Services/GitInspector.php` (`getTrackingDetails`, `getDirtyFiles`, `getStashesCount`,
  `getUnpushedTags`, `getRemoteUrl`, `fetch`)
* `src/Services/ComposerManager.php` (`ensureComposerHooks` installs the shortcuts)
* `stubs/workspace.stub` (standalone runner: `check`, `push`, `pull`, restore-first logic)
* [docs/workspace-routines.md](https://github.com/alex-kassel/workspace-development-toolkit/blob/main/docs/workspace-routines.md) (full human guide)

> This skill is auto-published from the toolkit. Auto-publish installs it only when absent; after editing
> the toolkit source, remove `.agents/skills/workspace-routines` in the host and run any artisan command to
> reinstall the updated copy.
* Tests: `tests/Feature/Commands/WorkspaceGitCommandsTest.php`,
  `tests/Feature/WorkspaceRoutineMaterializationTest.php`,
  `tests/Unit/GitInspectorTrackingTest.php`, `tests/Unit/DTOs/GitRepositoryStateTest.php`

## Testing and regression discipline

* The toolkit test suite has known pre-existing failures unrelated to the routine (at the time of writing:
  `PackageSkillsCommandTest` and one `WorkspaceCommandsTest` local-manifest test). Before blaming a change,
  verify the same failures exist on `HEAD` (e.g. `git stash -u`, run the failing test, `git stash pop`).
* Run at least:
  ```bash
  vendor/bin/phpunit --filter 'GitRepositoryStateTest|GitInspectorTrackingTest|WorkspaceGitCommandsTest|WorkspaceRoutineMaterializationTest'
  vendor/bin/phpstan analyse --no-progress
  ```
  Only new PHPStan findings count as regressions; the package has pre-existing level-8 findings.
* Real-git tests create temporary bare remotes; do not replace them with Process fakes for push/pull paths.
