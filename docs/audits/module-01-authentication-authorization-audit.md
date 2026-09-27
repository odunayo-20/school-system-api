# Module 01 — Authentication & Authorization Audit

**Project:** `school-system-api` (Headless School Management API)
**Audit date:** 2026-09-27
**Auditor:** Module 01 implementation agent
**Scope:** Read-only audit. No application code was modified during this phase.

---

## 0. Executive summary

The project is an **unmodified Laravel 12 skeleton**. It contains the framework default
`users` table, the default `web` session guard, and nothing else. There is:

- **no** API route file,
- **no** API middleware group,
- **no** token/bearer authentication of any kind,
- **no** role, permission, policy or gate infrastructure,
- **no** Form Requests, API Resources, or custom exception handling,
- **no** `routes/api.php`, **no** `config/sanctum.php`.

The `users` table currently contains **0 rows** and all three migrations have already been
applied (batch 1), so the database is live but empty of application data.

**Consequence:** nothing in the requirements can be "preserved" or "reconciled" — there is no
existing authentication or authorization logic to conflict with. Module 01 is therefore a
greenfield build *inside* the Laravel 12 conventions already present (Pest, Pint, PSR-4
`App\`, `database/` factories & seeders, `bootstrap/app.php` middleware/exception config).

**Headline risk:** the project was scaffolded as a *stateful web* application
(`SESSION_DRIVER=database`, `routes/web.php` returning a Blade `welcome` view) while the
target is a *stateless headless API*. This is the single most consequential gap.

---

## 1. Current authentication architecture

### 1.1 How users authenticate

There is **no authentication mechanism at all**. The `User` model extends
`Illuminate\Foundation\Auth\User` and the framework default is present (`email`, `password`,
`remember_token`, `password_reset_tokens`), but no controller, route, or form ever triggers
`Auth::attempt()`. The `web` guard *could* be used, but nothing uses it.

### 1.2 Guard

| Item | Value | Source |
|---|---|---|
| Default guard | `web` | `config/auth.php:19` (`env('AUTH_GUARD', 'web')`) |
| Defined guards | `web` only | `config/auth.php:40-44` |
| `web` driver | `session` | `config/auth.php:42` |
| API guard | **absent** | — |

### 1.3 Provider

| Item | Value | Source |
|---|---|---|
| Providers | `users` | `config/auth.php:64-74` |
| Driver | `eloquent` | `config/auth.php:66` |
| Model | `App\Models\User` (`env('AUTH_MODEL')`) | `config/auth.php:67` |
| Password broker | `users` | `config/auth.php:99-104` |
| Reset token table | `password_reset_tokens` | `config/auth.php:98` |
| Reset token expiry | 60 minutes | `config/auth.php:99` |
| Reset throttle | 60 seconds | `config/auth.php:100` |

### 1.4 Session-based or token-based?

**Session-based, by default and by configuration.** Evidence:

- `.env` / `.env.example`: `SESSION_DRIVER=database` (line 30), `SESSION_LIFETIME=120`.
- The `sessions` table is created by the users migration (`0001_01_01_000000_create_users_table.php:30-37`).
- The only guard is a `session` driver guard.
- `CSRF` middleware is part of the default `web` group, implying cookie/session semantics.

### 1.5 Is the API stateless?

**No — the application is currently entirely stateful, and there is no API.**

- No `routes/api.php` file exists, and `bootstrap/app.php:8-12` registers only `web`,
  `commands` and `health`. The `api` route file / `api` middleware group is never loaded.
- `SAME_SITE`/session cookies would be the only credential carrier.

A stateless API requires either bearer tokens or a cookie-less session. Neither is configured.

### 1.6 Existing authentication endpoints

**None.** `routes/web.php` contains a single closure returning `view('welcome')`.
There are no auth controllers, no `/login`, no `/api/v1/auth/*`.

---

## 2. Existing authorization architecture

| Concern | Finding |
|---|---|
| Roles | **None.** No `roles` table, no role enum, no `role_id` on `users`. |
| Permissions | **None.** No `permissions` table, no pivot, no package. |
| Role middleware | **None.** No `app/Http/Middleware` directory. |
| Permission middleware | **None.** |
| Policies | **None.** No `app/Policies` directory. |
| Gates | **None.** `AppServiceProvider::boot()` is empty. No `AuthServiceProvider` exists. |
| `Gate::before` super-admin bypass | **None.** |
| Third-party RBAC package | **None installed** — `vendor/spatie/laravel-permission` absent, not in `composer.json`. |

`bootstrap/app.php:13-17` has an empty `withMiddleware()` and an empty `withExceptions()` closure.

---

## 3. Database audit

### 3.1 Migration inventory (all applied, batch 1)

| Migration | Tables |
|---|---|
| `0001_01_01_000000_create_users_table.php` | `users`, `password_reset_tokens`, `sessions` |
| `0001_01_01_000001_create_cache_table.php` | `cache`, `cache_locks` |
| `0001_01_01_000002_create_jobs_table.php` | `jobs`, `job_batches`, `failed_jobs` |

`php artisan migrate:status` → all `Ran`. `App\Models\User::count()` → **0**.
No data loss risk exists; the database holds no user data.

### 3.2 `users` table (actual schema)

```
id                  bigint unsigned autoincrement  PK
name                varchar(255)                    NOT NULL
email               varchar(255)                    NOT NULL  UNIQUE
email_verified_at   timestamp                       NULL
password            varchar(255)                    NOT NULL
remember_token      varchar(100)                    NULL
created_at          timestamp                       NULL
updated_at          timestamp                       NULL
```

### 3.3 `password_reset_tokens` table

```
email        varchar(255)  PK
token        varchar(255)  NOT NULL
created_at   timestamp     NULL
```

Correct Laravel default. Token is a hash of a random string; no plaintext token is stored.

### 3.4 `sessions` table

```
id             varchar(255)  PK
user_id        bigint        NULL, INDEX          <-- no FK constraint
ip_address     varchar(45)   NULL
user_agent     text          NULL
payload        longtext      NOT NULL
last_activity  integer       INDEX
```

### 3.5 Foreign keys across the whole database

**None.** `Schema::create` in all three migrations produces zero `foreign()` constraints.
`users` has no outbound FK; `sessions.user_id` is a bare indexed integer.

### 3.6 Unique constraints

| Table | Constraint |
|---|---|
| `users` | `email` UNIQUE |
| `password_reset_tokens` | `email` PRIMARY KEY |
| `sessions` | `id` PRIMARY KEY |
| `cache` / `cache_locks` | `key` PRIMARY KEY |
| `failed_jobs` | `uuid` UNIQUE |

### 3.7 Indexes

| Table | Indexes |
|---|---|
| `users` | PK, unique `email` |
| `sessions` | PK, `user_id`, `last_activity` |
| `cache` | PK, `expiration` |
| `jobs` | PK, `queue` |

### 3.8 Missing fields (against the required target)

| Required field | Present? | Note |
|---|---|---|
| `id`, `name`, `email`, `password` | Yes | — |
| `email_verified_at` | Yes | present, but `User` does **not** implement `MustVerifyEmail` (see 3.10) |
| `status` | **No** | cannot represent ACTIVE / INACTIVE / SUSPENDED |
| `last_login_at` | **No** | cannot audit access |
| `remember_token` | Yes | vestigial for a token API but harmless |
| `created_at`, `updated_at` | Yes | — |
| role reference | **No** | no `roles` table, no `role_id` |

### 3.9 Missing tables / duplicate concepts

- Missing: `roles`, `permissions`, `permission_role`, `permission_user`, `staff`,
  `personal_access_tokens`.
- Duplicate concepts: **none found.** Because the project is empty there is no legacy
  `user_types` / `admins` / `teachers` / `students` table that would need consolidating.
  This is important: the "single `users` table" design can be adopted cleanly.
- The `sessions` table is a *session-driver* artefact, not an authentication requirement.
  It should be retained (harmless) but must not be relied upon for API auth.

### 3.10 Security problems

| # | Severity | Finding |
|---|---|---|
| 1 | CRITICAL | **No API authentication whatsoever.** Every current route is fully public, including `/`. |
| 2 | CRITICAL | **No authorization layer.** There is no way to express or enforce role/permission, so any future endpoint is unprotected by default. |
| 3 | CRITICAL | **No brute-force protection.** `AppServiceProvider::boot()` defines no `RateLimiter`. `php artisan route:list` shows no `throttle` middleware. The `ThrottleRequests` middleware is registered in the framework but never applied. |
| 4 | HIGH | **`email_verified_at` exists but verification is impossible.** `App\Models\User` has `MustVerifyEmail` deliberately commented out (`app/Models/User.php:5`). The column is decorative; unverified accounts are indistinguishable from verified ones to the application. |
| 5 | HIGH | **No account status.** There is no way to disable, suspend or lock an account. The only revocation available is deleting the row, which destroys the audit trail. |
| 6 | HIGH | **No `last_login_at`.** Access cannot be forensically reviewed after a breach. |
| 7 | MEDIUM | **Stateful architecture on an API project.** `SESSION_DRIVER=database` + a Blade route means the deployed app will try to serve HTML and set cookies. A pure API must not depend on this. |
| 8 | MEDIUM | **No global exception → JSON contract.** `withExceptions()` is empty, so an unauthenticated request to a future protected route would follow framework defaults and, in a non-JSON request, redirect to a `login` route that does not exist (`Route [login] not defined`) instead of returning `401`. |
| 9 | MEDIUM | **`sessions.user_id` has no FK constraint.** Orphaned session rows are possible; no referential integrity between sessions and users. |
| 10 | MEDIUM | **No `personal_access_tokens` table.** Once tokens are introduced there is nowhere to store them. |
| 11 | LOW | `email` is `varchar(255)` with default collation. On MySQL this is effectively case-insensitive, so `A@b.com` and `a@b.com` collide unpredictably across engines. Module 1 should normalise to lowercase on write. |
| 12 | LOW | `remember_token` is retained but will be dead weight for a bearer-token API. Removing it is a destructive migration on a column some deployments rely on; it will be retained. |
| 13 | LOW | `APP_DEBUG=true` in `.env`. Acceptable for local, must be `false` in production (deployment concern, documented). |
| 14 | LOW | `UserFactory` sets `'email_verified_at' => now()` and a `remember_token` by default, so factory-made users are always verified. Convenient, but it means the `unverified()` state is only reachable explicitly. |
| 15 | OK | Password hashing is correct: `protected function casts()` maps `'password' => 'hashed'` (`app/Models/User.php:47`). Laravel hashes on assignment; plaintext is never persisted. |
| 16 | OK | `password` and `remember_token` are in `$hidden` (`app/Models/User.php:34`), so accidental serialisation of the model does not leak the hash. |
| 17 | OK | Password reset token storage uses Laravel's hashed-token broker — no plaintext reset tokens in the database. |
| 18 | OK | `users.email` is UNIQUE, preventing duplicate login identities. |
| 19 | OK | `bcrypt` rounds configured via `BCRYPT_ROUNDS` (12 in env, 4 in `phpunit.xml`). |
| 20 | OK | Mail is not wired to a real transport (`MAIL_MAILER=log`), so no credential or reset-link leakage to a live SMTP in this environment. |

---

## 4. Gap analysis

Severity key: **CRITICAL** blocks the module; **HIGH** breaks a stated requirement;
**MEDIUM** weakens security/maintainability; **LOW** hygiene; **OK** verified good, keep.

### A. Authentication

| # | Sev | Gap | Required resolution |
|---|---|---|---|
| A1 | CRITICAL | No token authentication package | Install **Laravel Sanctum** (the framework-recommended choice for a first-party Laravel API; `php artisan install:api` is available in this version). Do **not** add Passport (full OAuth2 server — over-engineering for a single-school first-party client) or a JWT library (Laravel's own first-party solution should be preferred). |
| A2 | CRITICAL | No `routes/api.php`; `api` middleware group never loaded | Create `routes/api.php` and register it in `bootstrap/app.php` with `apiPrefix: 'api/v1'`. |
| A3 | CRITICAL | No `auth:api` guard | Add `sanctum` guard to `config/auth.php` guards; `config/sanctum.php` published for token expiry. |
| A4 | CRITICAL | No login / logout / me endpoints | Implement per §10. |
| A5 | HIGH | No `personal_access_tokens` table | Publish Sanctum's migration (additive, non-destructive). |
| A6 | HIGH | Password reset has no API surface | Expose forgot/reset using Laravel's `Password` broker + `ResetPassword` notification. Never hand-roll tokens. |
| A7 | HIGH | No rate limiting anywhere | Define named limiters (`api`, `login`, `auth`) in `AppServiceProvider::boot()`; apply `throttle` to the `api` group and stricter limits on login and password-reset. |
| A8 | HIGH | Credential enumeration risk on password reset | Always return a generic 200 message; do not branch the response on whether the email exists. |
| A9 | MEDIUM | No JSON exception contract | Map `AuthenticationException → 401`, `AccessDeniedHttpException/AuthorizationException → 403`, `ValidationException → 422`, `ThrottleRequestsException → 429`, `NotFoundHttpException → 404` in `withExceptions()`. |
| A10 | MEDIUM | Email verification column present but flow impossible | Implement `MustVerifyEmail` on `User` + Laravel's signed verification routes. **Document the decision** that unverified users are *not* blocked from logging in (admins provision accounts by email, so requiring verification would lock out the first Super Admin); verification is enforced selectively per-endpoint via middleware. |
| A11 | MEDIUM | Stateful defaults for a headless API | Keep `web` guard (needed for the broker and the existing `/` route) but ensure **all** `/api/v1` routes use the stateless `auth:api` guard. Do not add `EnsureFrontendRequestsAreStateful` to the API group. |
| A12 | LOW | Email case-sensitivity | Normalise `email` to lowercase+trim on write and on login lookup. |
| A13 | LOW | No consistent response envelope | Adopt `{ "data": ..., "message": ... }` for success; framework-standard 422 for validation. |
| A14 | OK | Password hashing already correct | Keep the `hashed` cast. |
| A15 | OK | `AppServiceProvider` is a safe place for limiters | No new provider bootstrap cost; `AuthServiceProvider` will be added for gates. |

### B. Authorization

| # | Sev | Gap | Required resolution |
|---|---|---|---|
| B1 | CRITICAL | No role model | Add `roles` table + `App\Enums\Role` with exactly `SUPER_ADMIN, ADMIN, REGISTRAR, STAFF, STUDENT`. **No** `TEACHING_STAFF` / `NON_TEACHING_STAFF` roles. |
| B2 | CRITICAL | No permission model | Add `permissions` table + `permission_role` pivot. `name` UNIQUE (e.g. `students.view`). |
| B3 | CRITICAL | No way to deny a Student from admin endpoints | Add `role` + `permission` middleware and register aliases; nothing is protected by default until these exist. |
| B4 | HIGH | No centralised super-admin bypass | Implement `Gate::before` returning `true` for `SUPER_ADMIN` in a dedicated `AuthServiceProvider`. Explicitly **not** `if ($user->role === ...)` in controllers. |
| B5 | HIGH | Staff members cannot be distinguished | Add a separate `staff` table (`user_id` unique, `staff_type`) — **not** staff columns on `users`. Teaching vs non-teaching is a classification, not a role. |
| B6 | HIGH | "Not all staff have the same permissions" is unexpressible | Add a `permission_user` pivot so individual accounts can hold permissions directly. Module 1 seeds no rows here; Modules 3+ can differentiate teaching/non-teaching staff without inventing new auth roles. |
| B7 | MEDIUM | No policy pattern established for future modules | Register policies in the new `AuthServiceProvider`. Module 1 defines **no** fake policies for unbuilt resources. |
| B8 | MEDIUM | No account status enforcement | Add `status` column + `UserStatus` enum + middleware that rejects non-`ACTIVE` accounts at login **and** on every authenticated request (so a suspension takes effect immediately, not at next login). |
| B9 | MEDIUM | No login auditing | Add `last_login_at`, updated by the authentication service. |
| B10 | LOW | No roles in the DB for existing users | `role_id` must be nullable in the additive migration (no rows exist, but the migration stays non-destructive and reversible), with an application-level guard that a user without a role cannot authenticate. |
| B11 | LOW | No seeder for roles/permissions | Idempotent `RoleSeeder` + `PermissionSeeder` (keyed on natural keys, so re-running cannot duplicate). |
| B12 | LOW | No dev super admin | Seed one, guarded by `app()->environment(['local','testing'])` and a password sourced from `SUPER_ADMIN_PASSWORD` env, never a hardcoded production secret. |
| B13 | OK | No conflicting third-party RBAC to remove | Nothing to uninstall. |

### C. Conventions, quality and tooling

| # | Sev | Gap | Required resolution |
|---|---|---|---|
| C1 | HIGH | `RefreshDatabase` is commented out in `tests/Pest.php:15` | Enable it for `Feature` tests; use an in-memory SQLite (`phpunit.xml` already sets `DB_DATABASE=:memory:`). |
| C2 | MEDIUM | No Form Requests | `LoginRequest`, `ForgotPasswordRequest`, `ResetPasswordRequest`; password rules centralised in one place. |
| C3 | MEDIUM | No API Resources | `UserResource` as the single whitelisted representation of a user. |
| C4 | MEDIUM | No service layer | `AuthenticationService` (authenticate / logout / current user context) so controllers stay thin. |
| C5 | MEDIUM | Tests only cover the welcome page | Add auth, authorization and staff-type feature tests. Authorization middleware is tested against **routes registered inside the test**, so no placeholder production endpoints for future modules are created. |
| C6 | LOW | No API documentation | `docs/api/authentication.md`. |
| C7 | OK | `laravel/pint` present | Run `vendor/bin/pint` to keep formatting consistent. |
| C8 | OK | Pest 3 + `pest-plugin-laravel` present | Write tests in Pest style to match the project. |
| C9 | OK | Framework defaults are sound | `Authenticatable` base, `Notifiable`, factory & seeder conventions — all reused, none replaced. |

---

## 5. Target architecture decision summary

| Decision | Choice | Rationale |
|---|---|---|
| Token mechanism | **Laravel Sanctum** | Laravel 12 ships `install:api`; first-party; opaque rotating tokens, no OAuth2 server overhead. |
| API prefix | `/api/v1` via `withRouting(apiPrefix:)` | Versioning retained for the lifetime of the product. |
| User identity | one `users` table for every role | Avoids per-role auth systems and cross-table permission unions. |
| Role storage | `users.role_id → roles.id` (nullable) | Single role matches the domain wording ("`role = STAFF`"); indexed lookups; non-destructive migration. |
| Staff classification | separate `staff` table with `staff_type` | Keeps staff concerns off `users` per §4. |
| Teaching vs non-teaching | `StaffType` enum + `permission_user` grants | A classification, not an auth role. Distinct permissions without duplicate login systems. |
| Super-admin bypass | single `Gate::before` in `AuthServiceProvider` | Centralised; no controller-level `if` statements (§7). |
| Permission enforcement | Gates keyed by permission name + `permission:` middleware | `Gate::allows('students.view')` works in controllers, policies and middleware identically. |
| Inactive/suspended users | blocked at login **and** per-request middleware | Immediate revocation. |
| Email verification | `MustVerifyEmail` + standard signed routes; **not** required for login | Admin-provisioned accounts would otherwise be locked out. Enforced per-endpoint where justified. |
| Password reset | Laravel `Password` broker + `ResetPassword` notification | No bespoke cryptography. Generic response to prevent enumeration. |
| Response envelope | `{ "data": ..., "message": ... }`; framework-standard 422/401/403/429 | Consistent, machine-readable. |
| Tests | Pest feature tests, `RefreshDatabase`, in-memory SQLite | Matches existing tooling. |

---

## 6. Out of scope (explicitly NOT implemented)

Per §1 and §25, this module deliberately creates **no** functionality for: student
management, admission, enrollment, subjects, results, promotion, attendance, timetable or
fees. No placeholder models, policies, migrations or routes are created for them, and no
future business permissions are seeded. The only permissions seeded are those that belong to
the account/role domain itself (see the final report).

---

## 7. Preservation statement

The following existing artefacts are **preserved unchanged or extended additively**:

- `App\Models\User` (extended in place; existing `fillable`, `hidden`, `casts` kept).
- `config/auth.php` (guards extended, `web` guard and `users` provider untouched).
- `0001_01_01_000000_create_users_table.php` (**not** edited — new columns are added by a
  separate, additive migration so the original migration record stays truthful).
- `routes/web.php` and the `welcome` view route.
- `UserFactory` (extended with role/status/staff states; existing definition intact).
- `ExampleTest` (kept passing).
- The `sessions`, `cache`, `jobs` tables (untouched; no destructive migration is used, the
  database is never reset, and no data is dropped).
