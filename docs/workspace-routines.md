# Workspace Git Routines: Machine-to-Machine Workflow

This guide describes how to move safely between machines while developing multiple packages inside one
host Laravel application. It is written for both humans and AI agents; the compact agent-facing version is
published as the `workspace-routines` skill (`resources/boost/skills/workspace-routines/SKILL.md`) and is
materialized into `.agents/skills/workspace-routines` in the host project.

## The problem

* The host application is one git repository (the "recipe": `workspace.json`, `composer.json`,
  `composer.lock`, the generated `workspace` runner).
* Every package under `packages/<vendor>/<package>` is a separate git repository.
* When shutting a machine down, every repository must have a clean tree and all commits/tags must be on
  GitHub; otherwise work remains only on that machine.
* On another machine the host may already exist, some packages may be present but outdated, and some may be
  missing entirely. A plain `composer create-project` only solves the fresh-machine case, and
  `php workspace restore` only clones missing packages — it never updates existing ones.

## The three commands

| Command | Purpose |
| --- | --- |
| `composer workspace:check` | Read-only gate: the host and all packages must be clean and pushed. |
| `composer workspace:push` | Push committed work and tags to the remotes. |
| `composer workspace:pull` | Adopt the latest remote state on another machine. |

Each shortcut is installed into the host `composer.json` by the toolkit and is equivalent to the standalone
runner command:

```bash
php workspace check
php workspace push
php workspace pull
```

The underlying artisan commands are also available and are the actual implementation:

```bash
php artisan workspace:check
php artisan workspace:push
php artisan workspace:pull
```

> [!IMPORTANT]
> Prefer `composer workspace:pull` / `php workspace pull` when package directories may be missing.
> `php artisan` cannot boot while a required package directory is absent (the autoloader fails). The
> standalone runner restores missing packages first, without Laravel, and only then delegates to artisan.

## Restore-first recovery (why the runner is the entry point)

When a required workspace package is missing, the host autoloader points at classes that no longer exist:

```
include(vendor/composer/../alex-kassel/stub-engine/src/...ServiceProvider.php):
Failed to open stream: No such file or directory
```

Any artisan command, including `workspace:pull`, crashes before it can help. The standalone `workspace`
runner does not use `vendor/autoload.php`, so it can:

1. read `workspace.json`;
2. detect missing package directories (`workspace_missing_packages`);
3. clone them (`workspace_restore`);
4. then boot Laravel and delegate the rest (`workspace:pull`, `workspace:check`, `workspace:push`).

This is why `workspace:pull` behaves correctly on a machine where only part of the packages exist.

## Semantics

### `workspace:check`

* Inspects the host repository and every package.
* **Errors (exit code 1):**
  * working tree is dirty (modified/untracked/staged files);
  * package directory is missing;
  * directory is not an independent git repository;
  * detached HEAD;
  * no `origin` remote;
  * branch has commits but no upstream (`Unpublished`);
  * commits are ahead of upstream;
  * branch diverged from upstream.
* **Warnings (exit code 0):**
  * behind upstream;
  * stashes present (not transferred to other machines);
  * tags not pushed.
* Useful options: `--json`, `--fetch`, `--no-host`, `--workspace=<name>`.

The command never writes to the repositories. `--fetch` only updates remote-tracking refs.

### `workspace:push`

* Pushes every clean repository that has work to push; packages are processed before the host.
* Never commits, merges, or force-pushes.
* Dirty / detached / diverged / missing-remote repositories are reported and skipped; the command exits
  non-zero if anything could not be synchronized.
* Branches without an upstream are pushed with `--set-upstream`.
* Tags are pushed with `--follow-tags`, and any remaining local-only tags are pushed explicitly.
* Useful options: `--dry-run`, `--json`, `--no-host`, `--no-tags`.

### `workspace:pull`

* Incremental and idempotent. It runs, in order:
  1. restore missing packages through the standalone runner (no Laravel required);
  2. fast-forward the host repository when it is behind;
  3. fast-forward every existing package; up-to-date packages are left untouched;
  4. run `composer install` only when `composer.json` / `composer.lock` changed or `vendor/` is missing;
  5. run migrations only when migration files changed (or `--migrate` is passed);
  6. install/build front-end assets only when `package.json` / `package-lock.json` changed (or `--assets`);
  7. clear application caches.
* Dirty packages are skipped and reported, never overwritten.
* Fast-forward only; diverged branches require manual resolution.
* A host that is not a git repository is skipped with a warning (normal after a fresh `create-project`, which
  strips VCS metadata). This does not block package synchronization.
* Useful options: `--dry-run`, `--json`, `--no-host`, `--no-install`, `--no-migrate`, `--no-assets`,
  `--migrate`, `--assets`, `--workspace=<name>`.

## Daily routine

### Before shutting down

```bash
composer workspace:check   # must exit 0
composer workspace:push    # commits + tags
```

If a repository is dirty, commit it intentionally (or stash/discard it), then rerun the commands. The
routine never commits on your behalf.

### Resuming on another machine

```bash
composer workspace:pull
composer workspace:check
```

This works for all mixed states: host present or absent as a git repository, packages present/synced,
present/outdated, or missing.

### One-time upgrade of a machine created before the routines existed

```bash
git pull
git -C packages/alex-kassel/workspace-development-toolkit pull
composer install
php artisan workspace:sync
```

After that, `composer workspace:pull` and the rest are available.

## Safety guarantees

* No automatic commits.
* No force pushes, no `git reset --hard`, no `git clean`, no deleting package directories.
* Pull is fast-forward only and leaves dirty trees untouched.
* Pushing commits and annotated tags only; other tags are pushed explicitly and reported if they fail.
* The host repository is included by default because it carries the recipe; use `--no-host` only
  deliberately.

## Troubleshooting

| Symptom | Meaning / action |
| --- | --- |
| `Missing workspace packages: ...` | Run `composer workspace:pull` (runner restores them). Do not use `php artisan` first. |
| `Class not found` / `Failed to open stream` | A package directory is missing or corrupted. Use `php workspace pull` / `composer workspace:pull`. |
| `Working tree is dirty` | Commit/stash/discard intentionally. The routine will not do it. |
| `Branch has diverged` | Resolve manually (`git fetch`, inspect, merge/rebase). Routine stays fast-forward-only. |
| `Branch has ... unpushed commit(s) and no upstream` | `workspace:push` will set upstream and push. |
| `Behind upstream` in check | Warning only. Run `workspace:pull` when convenient. |
| Host warning `not a git repository` | Normal for a fresh `create-project`. Initialize VCS only if you want to push the host from this machine. |
| `workspace:*` command not found | The machine predates the routine; run the one-time upgrade above. |
| `composer create-project` installed an old template | It picks the latest stable tag. Check host tags; cut a new tag after significant template changes. |
| Composer flags through shortcuts | `composer workspace:push --dry-run` does not forward flags. Use `php workspace push --dry-run` or `composer run-script workspace:push -- --dry-run`. |

## Implementation map (toolkit)

* Commands: `src/Commands/WorkspaceCheckCommand.php`, `WorkspacePushCommand.php`, `WorkspacePullCommand.php`
* Core service: `src/Services/WorkspaceGitService.php`
* DTOs: `src/DTOs/GitRepositoryState.php`, `src/DTOs/GitOperationResult.php`
* Git inspection: `src/Services/GitInspector.php`
* Shortcut materialization: `src/Services/ComposerManager.php` (`ensureComposerHooks`)
* Install pipeline: `src/Processors/Workspace/ComposerScriptsWorkspaceProcessor.php`,
  `src/Services/WorkspaceInstaller.php`
* Standalone runner: `stubs/workspace.stub` (contains `workspace_check`, `workspace_push`, `workspace_pull`,
  `workspace_missing_packages`, `workspace_restore` and the artisan delegation)
* Agent skill: `resources/boost/skills/workspace-routines/SKILL.md` (auto-published to `.agents/skills`)

## Updating the published agent skill

Auto-publish installs a bundled skill only when it is **absent** (it compares the `origin` field). If you
edit `resources/boost/skills/workspace-routines/SKILL.md`, the already-installed host copy will not update
by itself. To refresh it:

```bash
rm -rf .agents/skills/workspace-routines
php artisan list   # any artisan boot triggers auto-publish
```

Commit the refreshed `.agents/skills/workspace-routines` together with the host lock update. Do not edit
only the host copy: the toolkit source is canonical.
* Tests:
  * `tests/Feature/Commands/WorkspaceGitCommandsTest.php` — real git repositories and bare remotes
  * `tests/Feature/WorkspaceRoutineMaterializationTest.php` — composer shortcuts, runner content, missing
    package gate
  * `tests/Unit/GitInspectorTrackingTest.php` — tracking/dirty/stash/tag/remote parsing
  * `tests/Unit/DTOs/GitRepositoryStateTest.php` — error/warning classification

## Tests and known pre-existing failures

Run the routine tests:

```bash
vendor/bin/phpunit --filter 'GitRepositoryStateTest|GitInspectorTrackingTest|WorkspaceGitCommandsTest|WorkspaceRoutineMaterializationTest'
vendor/bin/phpstan analyse --no-progress
```

The toolkit suite currently has known pre-existing failures unrelated to the routine
(`PackageSkillsCommandTest`, one `WorkspaceCommandsTest` local-manifest test) and pre-existing PHPStan
level-8 findings. Before treating a failure as a regression, verify it also fails on `HEAD`:

```bash
git stash -u
vendor/bin/phpunit --filter '<failing test>'
git stash pop
```

## Versioning the host template

* The host template is installed with `composer create-project`, which resolves the latest stable tag.
* After significant template changes (new shortcuts, changed runner, changed `workspace.json`) create and
  push a new tag, otherwise new machines keep installing the previous state.
* `workspace:sync` regenerates the runner and upgrades the managed composer shortcuts to the current
  toolkit implementation.
