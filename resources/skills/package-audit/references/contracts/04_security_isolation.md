# AUDIT CONTRACT: 04_SECURITY_ISOLATION

## 1. Role & Objective
Act as a senior application-security engineer auditing a third-party Laravel package.

Find vulnerabilities, unsafe constructs, and isolation violations, ensuring the package cannot damage, pollute, or compromise the host Laravel application or crash under persistent worker environments like Laravel Octane.

---

## 2. Scope of Investigation

### 1. Injection Vulnerabilities
- **SQL Injection**: Unbound raw SQL expressions (`DB::raw()`, `whereRaw()`, `orderByRaw()`), raw column concatenation, dynamic table/column string interpolation.
- **Command & Code Injection**: Shell execution (`exec()`, `shell_exec()`, `proc_open()`, `passthru()`, `system()`), `eval()`, dynamic method execution on unsanitized user input.
- **Template Injection**: Unsafe Blade unescaped output (`{!! $variable !!}`) rendering user-controlled content.

### 2. Cross-Platform Process Safety
- Flag raw shell commands (`exec()`, `shell_exec()`, `passthru()`) that assume POSIX shell syntax or Linux-only binaries (`chmod`, `chown`, `which`, `curl`).
- Enforce the native Laravel `Illuminate\Support\Facades\Process` facade or `Symfony\Component\Process\Process` with parameterized arguments.

### 3. Laravel Octane & State Isolation (CRITICAL)
- **Container Hijacking**: Overwriting existing host container bindings without conditional guards (`bindIf()`, `singletonIf()`).
- **Global Config Mutation**: Overwriting core Laravel config trees (e.g. `config(['app.timezone' => ...])`) at runtime.
- **State Leakage across Requests**: Singletons registered in the container MUST NOT store state tied to a single HTTP request (e.g. `$request`, current user, request-scoped tokens).
- **Static Property Accumulation**: Static arrays or memoization caches that grow indefinitely across Octane requests without a purge mechanism.

### 4. Secrets & Sensitive Data
- Hardcoded API tokens, private keys, passwords, or test credentials.
- Accidental exposure of `.env` or sensitive configurations in exception stack traces or debug dumps (`dd()`, `dump()`, `ray()`).
- Proper attribute hiding (`$hidden`) and encryption (`'casts' => ['secret' => 'encrypted']`) on Eloquent models.

### 5. Dependency Vulnerabilities
- Known security advisories in direct and transitive dependencies (`composer audit`).

---

## 3. Mandatory Rules & Boundaries
- **READ-ONLY in Phase 1**: Never attempt destructive exploit payloads or modify code during Phase 1.
- **Concrete Evidence Required**: Findings MUST contain a demonstrable attack path or clear logic proof. Do not flag standard built-in PHP functions if they are completely safe in context.
- Assign `root_cause_id: "RC-HOST-ISOLATION-*"` to any finding violating host application boundaries.

---

## 4. Output Deliverables
The agent produces a human-readable Markdown report: `<run-dir>/reports/security.md` containing:
- **AUDIT STATUS**: `PASS` | `FAIL` | `PARTIAL` | `BLOCKED` | `NOT_APPLICABLE`.
- **SECURITY & ISOLATION SUMMARY**: Vulnerability breakdown, Octane safety rating, host pollution assessment, `composer audit` status.
- **FINDINGS & THREATS**: Detailed breakdown with reproduction vectors, threat severity, and remediation steps.
