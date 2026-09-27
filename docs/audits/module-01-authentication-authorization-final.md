# Module 01 — Authentication & Authorization: Final Report

**Project:** `school-system-api` (Headless School Management API)
**Module:** 01 — Authentication & Authorization
**Date:** 2026-09-27
**Preceding document:** [`module-01-authentication-authorization-audit.md`](./module-01-authentication-authorization-audit.md)
**API reference:** [`../api/authentication.md`](../api/authentication.md)

---

## 0. Status

| Check | Result |
|---|---|
| `php artisan test` | **87 passed**, 259 assertions, 0 failed, 0 skipped |
| `vendor/bin/pint --test` | **passed** |
| `php artisan migrate:fresh --seed` | **passed** on a clean database |
| `php artisan route:list --path=api` | 7 routes, all registered |
| Live HTTP smoke test | all status codes and JSON bodies correct |
| Modules 2+ started | **no** — nothing outside Module 01 was built |

Module 01 is complete.

---

## 1. What was built

A stateless, token-based authentication and role/permission authorization layer for the
school management API, following the audit's target architecture with no deviation.

### 1.1 Stack decisions

| Concern | Choice | Why |
|---|---|---|
| Token mechanism | **Laravel Sanctum** `v4.3.3` | First-party, opaque rotating tokens, no OAuth2 server to run |
| API prefix | `/api/v1` via `withRouting(apiPrefix:)` | Versioning retained for the product's life |
| Guard | `api` (Sanctum), `web` left untouched | Bearer token only; the broker and the existing web route still work |
| Session in the API | **none** | `EnsureFrontendRequestsAreStateful` deliberately not enabled; `SANCTUM_STATEFUL_DOMAINS` empty |
| Password hashing | bcrypt via the existing `hashed` cast | Framework default, already correct — kept |
| Password reset | Laravel `Password` broker | No bespoke token cryptography; tokens stored hashed, 60-minute expiry, single use |
| Tests | Pest 3, `RefreshDatabase`, in-memory SQLite | Matches the tooling already in the project |

### 1.2 Deliverables

**Enums** — `app/Enums/`
`Role` (`SUPER_ADMIN`, `ADMIN`, `REGISTRAR`, `STAFF`, `STUDENT`), `UserStatus`
(`ACTIVE`, `INACTIVE`, `SUSPENDED`), `StaffType` (`TEACHING`, `NON_TEACHING`).

**Models** — `app/Models/`
`Role`, `Permission`, `Staff`; `User` extended with `HasApiTokens`, `MustVerifyEmail`,
`status`, `role`, `staff` and the `directPermissions` relation.

**Migrations** — all additive, all reversible
`create_personal_access_tokens_table` (Sanctum's own), `create_roles_table`,
`create_permissions_table`, `create_permission_role_table`, `create_permission_user_table`,
`add_authentication_fields_to_users_table`, `create_staff_table`.

**Authorization** — `app/Providers/AuthServiceProvider.php`
A single `Gate::before` grants `SUPER_ADMIN` everything. Role checks are *themselves* a
Gate ability (`role`), so the bypass covers role middleware, permission middleware,
policies and ad-hoc `Gate::allows()` identically. Permissions are Gate abilities keyed
by name. No controller contains a role `if`.

**Middleware** — `app/Http/Middleware/`
`EnsureUserHasRole` (`role:`), `EnsureUserHasPermission` (`permission:`),
`EnsureAccountIsActive` (`active`), `EnsureEmailIsVerified` (`verified`).

**Endpoints** — 7 routes under `routes/api.php`, all in `App\Http\Controllers\Api\V1\Auth`
`login`, `logout`, `me`, `forgot-password`, `reset-password`, email verification link,
email verification re-send.

**Support** — `app/Services/Auth/AuthenticationService.php`,
`App\Support\ApiResponse`, `app/Http/Resources/UserResource.php`, Form Requests,
`app/Rules/PasswordRule.php`.

**Data** — `RoleSeeder`, `PermissionSeeder`, `SuperAdminSeeder` (dev-only, env-guarded).

**Docs** — `docs/api/authentication.md` (this module's reference) and the audit.

---

## 2. How the audit's findings were closed

| Audit ref | Finding | Resolution |
|---|---|---|
| A1 | No token package | Sanctum installed and published |
| A2 | No `routes/api.php` | Created, registered at `api/v1` |
| A3 | No `auth:api` guard | `api` guard added to `config/auth.php` |
| A4 | No login/logout/me | All three implemented |
| A5 | No `personal_access_tokens` | Sanctum's migration published |
| A6 | Password reset had no API | Broker + `ResetPassword` notification; tokens revoked on reset |
| A7 | No rate limiting | `api` 60/min, `login` 5/min, `auth` 5/min, all env-configurable |
| A8 | Credential enumeration on reset | Byte-identical 200 response; a test asserts the exact equality |
| A9 | No JSON exception contract | `401/403/404/422/429` + a debug-independent generic `500` |
| A10 | Verification impossible | `MustVerifyEmail` + working signed link; the login decision is documented |
| A11 | Stateful defaults on an API | `web` guard kept, `api` group is stateless |
| A12 | Email case sensitivity | Lowercased + trimmed on write and on login lookup |
| A13 | No response envelope | `ApiResponse::success/error` used everywhere |
| A14/A15 | Password hashing; provider placement | Kept as-is |
| B1 | No role model | `roles` table + `Role` enum, exactly the 5 required values |
| B2 | No permission model | `permissions` + `permission_role` + `permission_user` |
| B3 | Nothing protected by default | `role:` and `permission:` middleware + aliases |
| B4 | No super-admin bypass | One `Gate::before` in `AuthServiceProvider` |
| B5 | Staff indistinguishable | Separate `staff` table with `staff_type`; no extra roles |
| B6 | Per-account grants unexpressible | `permission_user` pivot, unioned with role permissions |
| B7 | No policy pattern | Permissions are Gate abilities — a future module seeds a row and it works |
| B8 | No status enforcement | Blocked at login **and** on every request; tokens revoked on suspension |
| B9 | No login auditing | `last_login_at` stamped on every successful login |
| B10 | Role on existing users | `role_id` nullable, FK, reversible; no-role users cannot log in |
| B11 | No seeders | Idempotent `RoleSeeder` + `PermissionSeeder` keyed on natural keys |
| B12 | No dev super admin | Env-guarded seeder, `SUPER_ADMIN_PASSWORD`, generated if absent |
| B13 | Conflicting RBAC package | None to remove |
| C1 | `RefreshDatabase` disabled | Enabled for `Feature` tests |
| C2 | No Form Requests | `LoginRequest`, `ForgotPasswordRequest`, `ResetPasswordRequest` |
| C3 | No API Resources | `UserResource`, the only user representation that leaves the API |
| C4 | No service layer | `AuthenticationService` owns every credential and token operation |
| C5 | Tests cover only the welcome page | 87 tests across auth, authorization, staff types and enums |
| C6 | No API documentation | `docs/api/authentication.md` |
| C7/C8/C9 | Pint, Pest, framework defaults | All reused, none replaced |
| 3.10 #1–#12 | Security findings | Each addressed above; #12 `remember_token` intentionally retained (removing it is destructive and unnecessary) |
| §3.10 #8 | Non-JSON request would hit `Route [login] not defined` | Found in the smoke test and fixed — see §4.1 |

---

## 3. Design decisions worth recording

### 3.1 One role per user, but permissions are additive

`users.role_id` holds exactly one role, matching the domain wording (`role = STAFF`).
Permission checks are the union of `permission_role` and `permission_user`, so a
teaching staff member can be granted `results.view` directly without inventing a
sixth role or a second login system.

### 3.2 Teaching vs non-teaching is a classification, not a role

`StaffType` lives on the `staff` table. A `TEACHING_STAFF` role was deliberately **not**
created: it would conflate "what may this person do" with "what kind of staff record
does this person have".

### 3.3 A newly seeded permission is enforced with no code change

The Gate is keyed by permission name rather than by a compiled list, so this works:

```php
// Module 5 seeds it
Permission::create(['name' => 'students.view', 'label' => 'View students']);

// Module 5 protects a route with it
Route::get('/students', StudentController::class)->middleware('permission:students.view');
```

`AuthorizationTest` proves it: a registrar is denied `students.view`, the permission is
created and attached **at runtime**, and the next request succeeds — no provider, Gate or
middleware was edited.

### 3.4 Unverified accounts can still log in

Verification is a standard flow, not a login gate. Administrators provision accounts by
email, so requiring verification to log in would lock out the very first Super Admin.
Sensitive endpoints opt in individually with `verified`. This is documented in
`docs/api/authentication.md` §4.1 and §4.6 and is a deliberate decision, not an omission.

### 3.5 Suspension takes effect immediately

`active` middleware runs on **every** authenticated request, not only at login, and
deletes all of the account's tokens when it trips. A suspension is therefore effective on
the next request rather than at next login.

### 3.6 No enumeration anywhere

| Endpoint | Defence |
|---|---|
| `login` | One message and status for unknown email, wrong password, inactive, suspended and role-less |
| `forgot-password` | Byte-identical 200 for a known and an unknown address (asserted by test) |
| `reset-password` | Token failure and password-policy failure are both `422` |
| `email/verify` | `403` with a vague message for a bad signature, unknown user, or hash mismatch |

### 3.7 Tokens are revocable and bounded

- 1440-minute lifetime (configurable), mirrored into `expires_at` for auditing.
- `smp_` prefix so a leaked token is recognisable.
- Logout revokes **only** the token used, so signing out one device does not sign out the
  others. Tested both ways.
- A password reset revokes **all** tokens, forcing re-authentication everywhere.

---

## 4. Defects found during verification and fixed

The test suite passed while real HTTP requests still failed. Live smoke testing against
`php artisan serve` was what surfaced these; none were visible from the test run alone.

### 4.1 Unauthenticated requests returned 500 instead of 401 — **fixed**

Laravel's `auth` middleware redirects a guest to `route('login')` unless the request
expects JSON. A headless API has no `login` route, so any client that omitted
`Accept: application/json` — e.g. plain `curl`, some Postman presets, server-to-server
calls — got `RouteNotFoundException` → 500.

Fixed with `redirectGuestsTo(fn () => null)` in `bootstrap/app.php`, so the
`AuthenticationException` always reaches the JSON handler.

**The same latent bug existed in the framework's `EnsureEmailIsVerified`**, which
branches on `expectsJson()` and otherwise redirects to `route('verification.notice')` —
also undefined here. It was replaced with `App\Http\Middleware\EnsureEmailIsVerified`,
which always answers with the API envelope.

Both are covered by regression tests that deliberately omit the `Accept` header
(`LoginTest`, `EmailVerificationTest`).

### 4.2 The email-verification link could never have worked — **fixed**

Two independent faults:

1. The route carried `auth:api`, so a link opened in a browser — where no API token
   exists — could never succeed.
2. Laravel's `VerifyEmail` notification builds its URL from the unprefixed route name
   `verification.verify`. Every route here is under the `auth.` prefix, so sending a
   *real* verification mail would have thrown `RouteNotFoundException`. The tests missed
   this because `Notification::fake()` never renders the mail.

Fixed by dropping `auth:api` from the route (the `signed` middleware plus the SHA-1
address check already prove authenticity, exactly as Laravel's own flow does) and by
registering `VerifyEmail::createUrlUsing()` in `AppServiceProvider`.

Verified live: a real notification produced a working link, which returned `200` with no
`Authorization` header; the unsigned variant returned `403 Invalid signature.`; following
it again returned `422`.

### 4.3 `expires_at` was always `null` — **fixed**

Sanctum enforces lifetime from `config('sanctum.expiration')` at validation time and
never copies it onto the row, so the login response advertised a token that looked
non-expiring. The effective expiry is now computed, returned, and persisted to the row.

### 4.4 Super Admin was denied its own role middleware — **fixed**

`EnsureUserHasRole` called `hasRole()` directly, bypassing the `Gate::before`, so
`SUPER_ADMIN` received `403` on `role:ADMIN` routes. Role checks are now a Gate ability,
so one bypass covers every enforcement point.

### 4.5 Permission names were silently corrupted — **fixed**

`resolvePermissions()` used `merge()` on collections keyed by permission name. `merge()`
renumbers integer keys and discards the string keys, so `permissionNames()` returned
`[0 => 'users.view', 1 => 'users.delete']` instead of a readable list. Now uses
`Collection::union()`.

### 4.6 `PasswordRule::make(true)` would have thrown a 500 — **fixed**

It called a non-existent `Illuminate\Validation\Rules\Password::confirmed()`. The
`confirmed` rule is a separate string rule, now applied explicitly in
`ResetPasswordRequest`. A syntax error in `PermissionFactory` (`"{$group}."..Str::random(8)`)
was fixed in the same pass.

### 4.7 The seeder printed a password into CI logs — **fixed**

`SuperAdminSeeder` echoed a generated password to stdout, which a deployment pipeline
captures into build logs. It now prints the credential **only when stdout is an
interactive terminal** (`posix_isatty`); in CI it instructs the developer to set
`SUPER_ADMIN_PASSWORD` instead. Re-seeding never rotates an existing password.

---

## 5. Test coverage

87 tests, 259 assertions, all passing.

| File | Covers |
|---|---|
| `tests/Unit/EnumsTest.php` | All three enums, `tryFrom` behaviour, permission-name format |
| `tests/Feature/Auth/LoginTest.php` | Success, token issue + expiry, no password/hash in the response, case-insensitive email, `last_login_at`, wrong password, unknown email, inactive, suspended, no role, 422 validation, 429 throttling, hashed storage, expired-token rejection, `401` without an `Accept` header |
| `tests/Feature/Auth/LogoutTest.php` | Current token revoked and unusable, other sessions survive, 401 when unauthenticated |
| `tests/Feature/Auth/CurrentUserTest.php` | Profile payload, permissions, suspension effective immediately with tokens cleared |
| `tests/Feature/Auth/PasswordResetTest.php` | Link sent, unknown address identical response, no extra notification, valid token resets, tokens revoked, all tokens revoked, weak password rejected, 429 |
| `tests/Feature/Auth/EmailVerificationTest.php` | Unverified can log in, notification requires auth, refused once verified, **real notification link works with no token**, signed link verifies, re-follow is 422, unsigned rejected, expired rejected, hash mismatch rejected, unknown user rejected, non-numeric id rejected, `verified` middleware blocks (with and without `Accept` header) |
| `tests/Feature/Auth/AuthorizationTest.php` | Role middleware, super-admin bypass through `role:` and `permission:`, permission middleware, direct user grants unioned with role grants, **`students.view` created at runtime and enforced with no code change** |
| `tests/Feature/Auth/StaffTypeTest.php` | `staff_type` present for staff, absent for non-staff, enum coverage |
| `tests/Feature/ExampleTest.php`, `tests/Unit/ExampleTest.php` | Laravel defaults still pass |

**Testing technique:** authorization middleware is exercised against routes registered
*inside the test* (`registerAuthorizationTestRoutes()`), so no placeholder production
endpoints for future modules were created.

**Deliberate test isolation detail:** a single test process handles several requests
against one application instance, and Laravel's auth manager caches the resolved user per
guard. Tests call `forgetResolvedUser()` (`app('auth')->forgetGuards()`) after any
mutation so the next request re-resolves the user from its token exactly as a real request
would. Without it, logout and suspension tests would pass against a stale cached user.

---

## 6. Live verification performed

Against `php artisan serve`, with a real seeded Super Admin:

| Scenario | Result |
|---|---|
| `login` with valid credentials | `200`, token issued, `expires_at` populated 24h out |
| `me` **without** `Accept: application/json` | `200` (was `500`) |
| `me` with a garbage token | `401 Unauthenticated.` (was `500`) |
| `login` with a wrong password | `401 The provided credentials are incorrect.` |
| `login` with a malformed body | `422` + `errors` |
| `forgot-password` for an unknown address | `200` generic message |
| Unknown API route | `404 {"message":"Resource not found."}` |
| `logout` | `200`, then the same token → `401` |
| Real verification notification link, no token | `200 Email address verified.` |
| Same link without its signature | `403 Invalid signature.` |
| Verified link followed twice | `422 Email address is already verified.` |
| Signed link with a tampered hash | `403` |
| `migrate:fresh --seed` on a clean database | all 10 migrations, all 3 seeders OK |

---

## 7. Residual risks and future work

| # | Risk | Severity | Recommendation |
|---|---|---|---|
| 1 | Tokens cannot be revoked per-device from the API. `logout` revokes the current token only; there is no "list my sessions" or "revoke all devices" endpoint. | LOW | Add a `GET /auth/tokens` + `DELETE /auth/tokens/{id}` endpoint when a settings UI exists. Module 1 deliberately keeps the surface minimal. |
| 2 | There is no refresh token. An expired token forces a full re-login. | LOW | Acceptable for a 24-hour lifetime. Revisit if the client is a mobile app with poor connectivity. |
| 3 | `permission_user` is never seeded. The mechanism is implemented and tested but unused. | LOW | Intentional — Module 1 seeds no per-account grants. Module 3+ differentiates teaching/non-teaching staff. |
| 4 | `staff` records have no create/update API. The table, model, enum, factory and `staff_type` exposure in `UserResource` all exist. | LOW | Out of scope; belongs to the user-management module that owns `users.create`. |
| 5 | Throttle keys use `env()` read at request time, so `config:cache` in production ignores `.env`. | MEDIUM | Move these three values into `config/` if the deployment runs `config:cache`. They are already in `.env.example`. |
| 6 | `sessions`, `cache`, `jobs` tables remain but are unused by the API. | LOW | Harmless. The `web` guard still exists for the framework password broker. |
| 7 | `APP_DEBUG=true` locally; must be `false` in production. | LOW | Deployment concern. The `500` handler already suppresses details regardless of `APP_DEBUG`. |
| 8 | No token rotation on use. A stolen token is valid until it expires or is revoked. | LOW | Acceptable for a first-party single-school API over HTTPS. Sanctum supports hashed tokens; the `smp_` prefix aids incident response. |

---

## 8. Preservation statement (verified)

- `App\Models\User` — extended in place; existing `fillable`, `hidden` and `casts` kept.
- `config/auth.php` — `web` guard and `users` provider untouched; only added.
- `0001_01_01_000000_create_users_table.php` — **not edited**. New columns come from a
  separate additive migration, so the original migration record stays truthful.
- `routes/web.php` and the `welcome` view route — untouched.
- `UserFactory` — existing definition intact, states added.
- `ExampleTest` (feature and unit) — kept passing.
- `sessions`, `cache`, `jobs` tables — untouched. No destructive migration, no data
  dropped. `migrate:fresh` was run only against a development SQLite database that the
  audit had already confirmed held zero user rows.

---

## 9. Out of scope — confirmed not built

No functionality, model, migration, policy, route, permission or placeholder exists for
students, admissions, enrollment, subjects, results, promotion, attendance, timetables,
grades or fees. The only permissions seeded are the seven that belong to the account and
role domain itself. The audit's finding that nothing needed "reconciliation" was correct:
the project started as a bare Laravel skeleton, and everything in Module 01 is new.

---

## 10. How to run it

```bash
# .env
APP_URL=http://localhost:8000
SUPER_ADMIN_PASSWORD=choose-a-local-password

php artisan migrate --seed
php artisan serve
```

```bash
# verify
php artisan test            # 87 passed
vendor/bin/pint --test     # passed
php artisan route:list --path=api
```

Full request/response reference: [`../api/authentication.md`](../api/authentication.md).
