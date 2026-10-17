# Workspace Toolkit TODO

Deferred items that are not urgent enough to block the current routine work.

---

## 1. Make `workspace:install` safe for existing `AGENTS.md`

**Status:** open
**Detected:** 2026-10-17
**Area:** `workspace:install`, `SetupAgentsGuidelineAction`, `SetupBoostConfigAction`

### Problem

`workspace:install` and `boost:install` interact with `AGENTS.md` differently, and one toolkit mode is
destructive.

Verified experimentally on a host project with a curated `AGENTS.md`:

| Scenario | Result |
| --- | --- |
| `workspace:install` | `AGENTS.md` is skipped (`⏭ [guidelines] AGENTS.md already exists`). However, `boost.json` is modified: `guidelines` becomes `false` and `packages: ["alex-kassel/workspace-development-toolkit"]` is added. |
| `workspace:install --force` | `AGENTS.md` is **fully replaced** by `stubs/AGENTS.md.stub` (130 lines). `workspace.json` is also reset to an empty package list, and the `workspace` runner is rewritten. |
| `workspace:install --self` | By code inspection: the host `AGENTS.md` is symlinked to the package stub, and if the host file differs, its content is copied **into the package stub** first. This can overwrite the toolkit's own guideline file in the working tree. |
| `boost:install` | Replaces only the `<laravel-boost-guidelines>...</laravel-boost-guidelines>` block; content outside the block is preserved (verified with marker lines). |

Additional trap: the toolkit's `--force` stub is itself entirely a single Boost block. After
`workspace:install --force`, enabling Boost guidelines and running `boost:install` would replace the whole
visible content, and any project-specific sections are already gone.

The install output also reports `Created AGENTS.md from bundled guideline stub.` when it actually
**overwrote** an existing file.

### Proposed solution (to choose)

* **Option A (minimal):** before overwriting `AGENTS.md` in `--force` mode, copy the existing file to
  `AGENTS.md.bak` and print an explicit warning (`Overwriting existing AGENTS.md...`).
* **Option B (safer):** never overwrite an existing `AGENTS.md` on `--force`; require a separate explicit
  option (e.g. `--force-agents`) to regenerate it.
* **Option C (most flexible):** merge the bundled toolkit guidelines into the existing file outside the
  Boost block, or ship them as a separate guidelines document instead of replacing `AGENTS.md`.
* **Option D (`--self`):** stop copying host content into `stubs/AGENTS.md.stub`; keep the symlink target
  immutable or create a local override file that is Git-ignored in the toolkit package.
* **Always:** document the behavior in `README.md` and `docs/workspace-routines.md`, and add a test that
  proves an existing `AGENTS.md` survives `workspace:install --force` or is backed up.

### Acceptance criteria

- [ ] `workspace:install` without `--force` keeps `AGENTS.md` byte-identical.
- [ ] `workspace:install --force` does not lose the existing `AGENTS.md` silently (either preserves it,
      backs it up, or requires an explicit `--force-agents`).
- [ ] `workspace:install --self` does not silently overwrite `stubs/AGENTS.md.stub` in the package repo.
- [ ] Install output clearly distinguishes created vs overwritten vs preserved files.
- [ ] `boost.json` guidelines-protection behavior is documented (it sets `guidelines: false`).
- [ ] Regression tests cover all of the above.
