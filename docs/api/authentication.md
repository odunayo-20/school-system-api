# Authentication & Authorization API

**Module:** 01 — Authentication & Authorization
**Base URL:** `/api/v1`
**Auth scheme:** `Authorization: Bearer <token>` (Laravel Sanctum personal access tokens)

---

## 1. Conventions

### 1.1 Response envelope

Every endpoint in this API answers with one of two shapes.

**Success**

```json
{
  "data": { "...": "..." },
  "message": "Human readable summary."
}
```

`message` is omitted when an endpoint returns a bare object. A success is never returned
with a 4xx/5xx status.

**Failure**

```json
{
  "message": "Short, safe summary.",
  "errors": { "field": ["First problem.", "Second problem."] }
}
```

`errors` is present only for validation failures (422).

### 1.2 Status codes

| Code | Meaning | Typical body `message` |
|---|---|---|
| `200` | Success | endpoint specific |
| `401` | No valid token, or the token expired | `Unauthenticated.` |
| `403` | Valid token, but not allowed | `This action is unauthorized.` / `This account has been suspended.` |
| `404` | Unknown route or record | `Resource not found.` |
| `422` | Validation failed, or the action contradicts current state | endpoint specific + `errors` |
| `429` | Rate limit exceeded | `Too many requests.` |
| `500` | Unexpected server fault | `Server error.` |

The generic `500` body is deliberate: it is emitted regardless of `APP_DEBUG`, so a stack
trace, file path or SQL fragment can never reach a client.

### 1.3 Statelessness

The API is **stateless**. A client authenticates by sending a bearer token on every
request. There is no login cookie, no session and no CSRF token.

`Sanctum`'s `EnsureFrontendRequestsAreStateful` middleware is deliberately **not**
enabled and `SANCTUM_STATEFUL_DOMAINS` is empty, so a cookie can never be used to reach
these endpoints.

Because a failure is possible at any point, clients must send `Accept: application/json`.
This API answers with JSON either way, but the header lets the framework skip its
HTML-redirect fallback.

### 1.4 Rate limits

| Limiter | Applies to | Default | Key |
|---|---|---|---|
| `api` | every `/api/v1` request | 60/minute | user id, else client IP |
| `login` | `POST /auth/login` | 5/minute | lowercased email + client IP |
| `auth` | forgot/reset password, verification link | 5/minute | lowercased email + client IP |

All three are configurable:

```dotenv
API_RATE_LIMIT_PER_MINUTE=60
LOGIN_RATE_LIMIT_PER_MINUTE=5
AUTH_RATE_LIMIT_PER_MINUTE=5
```

---

## 2. The user resource

`data.user` and `data` (from `/me`) are both produced by `UserResource`, the only
representation of a user that leaves the API. Fields are whitelisted explicitly, so a
column added to the model later cannot leak by accident.

| Field | Type | Notes |
|---|---|---|
| `id` | integer | |
| `name` | string | |
| `email` | string | always lowercased and trimmed |
| `status` | string | `ACTIVE`, `INACTIVE` or `SUSPENDED` |
| `role` | string \| null | `SUPER_ADMIN`, `ADMIN`, `REGISTRAR`, `STAFF`, `STUDENT`; `null` only for a record that has not been assigned a role yet |
| `staff_type` | string | **only present for staff.** `TEACHING` or `NON_TEACHING` |
| `email_verified` | boolean | |
| `last_login_at` | string \| null | ISO-8601, set by the login endpoint |
| `permissions` | string[] | effective permission names: role permissions **plus** direct user grants |
| `created_at` | string | ISO-8601 |

`password` and its hash are never present. There is a test asserting the login response
body contains neither `password"` nor the `$2y$` bcrypt marker.

---

## 3. Roles and permissions

### 3.1 Roles

Exactly five roles exist, defined as `App\Enums\Role`:

`SUPER_ADMIN`, `ADMIN`, `REGISTRAR`, `STAFF`, `STUDENT`

Each user holds **one** role (`users.role_id`, nullable for migration safety). A user
with no role **cannot log in** — see §4.1.

Teaching vs non-teaching staff is a **classification, not a role**. It lives in the
separate `staff` table as `staff_type` (`TEACHING` / `NON_TEACHING`). It never becomes a
sixth role, and it never duplicates a login system.

### 3.2 Permissions

Permissions are dot-separated names in a `permissions` table, joined to roles through
`permission_role` and to individual users through `permission_user`. The two sources are
merged, so a permission granted directly to one person works without inventing a role.

The Gate is keyed by permission name, so a **newly seeded permission is enforced with no
code change**:

```php
Gate::define('students.view', fn (User $user) => $user->hasPermission('students.view'));
```

```php
// in routes/api.php, once the owning module ships the permission
Route::get('/students', StudentController::class)
    ->middleware('permission:students.view');
```

A feature test covers this: a registrar is denied `students.view`, the permission is
created and attached at runtime, and the very next request succeeds — no Gate, provider
or middleware was edited.

### 3.3 Permissions seeded by Module 01

Only permissions belonging to the account/role domain are seeded. Business permissions
(`students.*`, `admissions.*`, `results.*`, …) are left to the module that owns them.

| Permission | `SUPER_ADMIN` | `ADMIN` | `REGISTRAR` | `STAFF` | `STUDENT` |
|---|:--:|:--:|:--:|:--:|:--:|
| `profile.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `profile.update` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `users.view` | ✓ | ✓ | ✓ | | |
| `users.create` | ✓ | ✓ | | | |
| `users.update` | ✓ | ✓ | | | |
| `users.delete` | ✓ | | | | |
| `roles.manage` | ✓ | | | | |

### 3.4 Super Admin bypass

`App\Providers\AuthServiceProvider` registers a single `Gate::before` that returns `true`
for `SUPER_ADMIN`. Because role checks are *also* a Gate ability (`role`), the bypass
covers role middleware, permission middleware, policies and any ad-hoc
`Gate::allows()` in a controller — identically, from one place.

No controller contains a `if ($user->role === ...)` shortcut.

### 3.5 Middleware aliases

| Alias | Effect when it fails |
|---|---|
| `auth:api` | `401 Unauthenticated.` |
| `active` | `403 This account has been suspended.` (or `…is not active.`) + all tokens revoked |
| `role:ADMIN,REGISTRAR` | `403 This action is unauthorized.` |
| `permission:users.view` | `403 This action is unauthorized.` |
| `verified` | `403 Your email address is not verified. …` |

`active` runs on every authenticated request, not just at login, so a suspension takes
effect on the **next request** rather than at next login.

`auth:api` and `verified` are configured to always answer with the API envelope, even
when the client omits `Accept: application/json`. The framework defaults would instead
try to redirect to `route('login')` / `route('verification.notice')`, neither of which
exists in a headless API, and the request would fail with a 500. Both are covered by
regression tests.

---

## 4. Endpoints

| Method | Path | Auth | Rate limiter |
|---|---|---|---|
| `POST` | `/api/v1/auth/login` | — | `login` |
| `POST` | `/api/v1/auth/logout` | token | `api` |
| `GET` | `/api/v1/auth/me` | token | `api` |
| `POST` | `/api/v1/auth/forgot-password` | — | `auth` |
| `POST` | `/api/v1/auth/reset-password` | — | `auth` |
| `GET` | `/api/v1/auth/email/verify/{id}/{hash}` | signed URL | `api` |
| `POST` | `/api/v1/auth/email/verification-notification` | token | `api` |

---

### 4.1 `POST /api/v1/auth/login`

Exchange credentials for an API token.

**Request**

```json
{
  "email": "superadmin@example.test",
  "password": "the-password"
}
```

| Field | Rules |
|---|---|
| `email` | required, valid email, max 255 |
| `password` | required, string, max 255 |

**`200`**

```json
{
  "data": {
    "user": { "id": 1, "name": "Super Admin", "...": "see §2" },
    "token": "2|smp_Me3sSoZtQA11ncdaB8lqn7xfAnAQpi3OzRITOugp67f967d9",
    "token_type": "Bearer",
    "expires_at": "2026-09-28T16:41:06+00:00"
  },
  "message": "Authenticated successfully."
}
```

Send the token verbatim as `Authorization: Bearer <token>`. Do not strip the `id|`
prefix — it is part of the credential.

`expires_at` reflects the lifetime Sanctum actually enforces
(`SANCTUM_TOKEN_EXPIRATION_MINUTES`, default 1440) and is also written to the
`personal_access_tokens.expires_at` column, so it can be audited later.

**`401`** — `The provided credentials are incorrect.`

The **same** message and status is returned for an unknown email, a wrong password, an
`INACTIVE` account, a `SUSPENDED` account and an account with no role. The endpoint
cannot be used to discover which addresses exist.

**`422`** — validation failed.

**`429`** — more than 5 attempts in a minute for the same email + IP.

A successful login stamps `users.last_login_at` with an ISO-8601 timestamp.

---

### 4.2 `POST /api/v1/auth/logout`

Revoke the token used to make this request. Other tokens held by the same account stay
valid, so signing out one device does not sign out the others.

**Request:** no body.

**`200`**

```json
{ "message": "Logged out successfully." }
```

**`401`** — no token, or the token was already revoked.

The deleted row is gone immediately: replaying the same token returns `401`.

---

### 4.3 `GET /api/v1/auth/me`

The authenticated user's profile and authorization context. A client should call this
after login and cache the result: it carries the role, status and the full effective
permission list, which is everything needed to drive client-side UI.

**`200`**

```json
{
  "data": {
    "id": 1,
    "name": "Super Admin",
    "email": "superadmin@example.test",
    "status": "ACTIVE",
    "role": "SUPER_ADMIN",
    "email_verified": true,
    "last_login_at": "2026-09-27T16:41:06+00:00",
    "permissions": ["profile.view", "profile.update", "users.view", "users.create", "users.update", "users.delete", "roles.manage"],
    "created_at": "2026-09-27T16:03:15+00:00"
  }
}
```

**`401`** — missing, malformed or expired token.

**`403`** — the account is no longer `ACTIVE`. The token is revoked as a side effect.

---

### 4.4 `POST /api/v1/auth/forgot-password`

Send a reset link by email.

**Request**

```json
{ "email": "staff@example.test" }
```

**`200`** — always, whether or not the address exists:

```json
{ "message": "If the account exists, password reset instructions have been sent." }
```

**`422`** — validation failed.
**`429`** — more than 5 requests per minute for the same email + IP.

The response body is byte-for-byte identical for a known and an unknown address, so this
endpoint cannot enumerate accounts. A feature test asserts that exact equality.

Reset tokens are stored hashed by Laravel's password broker, expire after 60 minutes,
and are **single use** — the broker deletes the row the moment the password is changed,
so a link cannot be replayed. Changing a password also revokes every API token the
account holds, forcing all devices to re-authenticate.

The link is opened in a browser, so `APP_URL` must be correct in the deployment or the
link will point at the wrong host. Laravel signs the *full* URL, so a link generated for
`http://localhost:8000` is rejected on any other host.

---

### 4.5 `POST /api/v1/auth/reset-password`

**Request**

```json
{
  "token": "the-token-from-the-email",
  "email": "staff@example.test",
  "password": "New-Password-123",
  "password_confirmation": "New-Password-123"
}
```

The new password must be at least 8 characters and contain a letter, a mixed-case pair, a
number and a symbol (`app/Rules/PasswordRule.php`).

**`200`**

```json
{ "message": "Password has been reset." }
```

**`422`** — for an unknown, expired or already-used token:

```json
{
  "message": "The password reset token is invalid or has expired.",
  "errors": { "email": ["This password reset token is invalid."] }
}
```

The same shape is used for a weak password (the weak-password messages appear under
`password`). Both are 422 so the endpoint reveals nothing about the token itself.

**`429`** — throttled.

---

### 4.6 `GET /api/v1/auth/email/verify/{id}/{hash}`

Mark an email address as verified. **Clients do not call this directly** — open the link
from the email.

**`200`**

```json
{ "message": "Email address verified." }
```

**`403`** — `Invalid signature.` (tampered or expired link) or `Invalid verification
link.` (the `{hash}` is not the SHA-1 of the address belonging to `{id}`, or no such
user). Both are deliberately vague so the endpoint cannot be used to probe accounts.

**`422`** — `Email address is already verified.`

**This route takes no bearer token, by design.** The link is opened in a browser
straight from the mailbox, where no API token exists. Authenticity comes from two
independent checks instead: the framework's `signed` middleware rejects a tampered or
expired URL, and the controller requires `{hash}` to be the SHA-1 of the address
belonging to `{id}`. The standard `email_verified_at` column is then written.

`App\Providers\AppServiceProvider` points Laravel's `VerifyEmail` notification at
`auth.verification.verify` via `createUrlUsing()`, because the framework default asks
for an unprefixed `verification.verify` route that this application does not define.

**Unverified accounts can still log in.** Administrators provision accounts by email, so
requiring verification to log in would lock out the very first Super Admin. Sensitive
endpoints opt in individually with the `verified` middleware.

---

### 4.7 `POST /api/v1/auth/email/verification-notification`

Re-send the verification email.

**Request:** no body. Requires a valid token.

**`200`**

```json
{ "message": "Verification link sent." }
```

**`401`** — no valid token.
**`403`** — account is not `ACTIVE`.
**`422`** — `Email address is already verified.` (and no mail is sent).

Throttled to 5/minute by email + IP.

---

## 5. Token lifecycle

- **Issued** by `POST /auth/login`, one row per login.
- **Lifetime** `SANCTUM_TOKEN_EXPIRATION_MINUTES`, default 1440 (24h). Enforced by
  Sanctum on every request, and mirrored into `expires_at` for auditing.
- **Prefix** `smp_` (`SANCTUM_TOKEN_PREFIX`) so a token is recognisable if it is ever
  found in a log or a repo. Not a security control — just a tripwire.
- **Revoked** by logout (that token only), by a password reset (all tokens), and by the
  `active` middleware the moment an account is suspended.
- **Not refreshable.** There is no refresh token; a client re-authenticates with
  credentials when the access token expires.

---

## 6. Client integration checklist

1. `POST /auth/login`, store `data.token` in memory (not `localStorage` if the client is
   a browser, where any XSS can read it).
2. Send `Authorization: Bearer <token>` and `Accept: application/json` on every request.
3. On `401`, clear the token and return to the login screen — `401` means the token is
   gone or expired, never that the user lacks rights.
4. On `403`, read `data.message`: it distinguishes *"This account has been suspended"*
   from *"This action is unauthorized"*, so the UI can show the right screen.
5. Use `GET /auth/me` to decide what to render. Hiding a button client-side is
   convenience only; the server re-checks every request.
6. Never log a token, a password or a reset token.

---

## 7. Local setup

```dotenv
# .env
APP_URL=http://localhost:8000
SANCTUM_TOKEN_EXPIRATION_MINUTES=1440
SANCTUM_TOKEN_PREFIX=smp_
SANCTUM_STATEFUL_DOMAINS=
SUPER_ADMIN_EMAIL=superadmin@example.test
SUPER_ADMIN_PASSWORD=choose-a-local-password
```

```bash
php artisan migrate --seed
php artisan serve
```

`SuperAdminSeeder` refuses to run outside `local`/`testing`, so it can never create a
default administrator on a production database. The password comes from
`SUPER_ADMIN_PASSWORD`; when that variable is empty a 16-character password is generated
and stored hashed.

**The generated password is printed only when standard output is an interactive
terminal.** In CI, stdout is a pipe, so the credential is not written to build logs —
set `SUPER_ADMIN_PASSWORD` in the environment instead.

Re-running the seeder never rotates an existing account's password.

### Verify it works

```bash
php artisan test              # 86 tests
vendor/bin/pint --test       # formatting
php artisan route:list --path=api
```

---

## 8. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `500` on an authenticated route | client omitted `Accept: application/json` | send the header; `redirectGuestsTo` is configured so the API still answers `401` |
| `401` immediately after login | a previous `php artisan migrate:fresh` deleted `personal_access_tokens` | log in again to mint a new token |
| `403 This account has been suspended.` | `status` is not `ACTIVE` | set `status` back to `ACTIVE`; note all tokens were revoked |
| `422 The password reset token is invalid or has expired.` | reset link older than 60 min, or already used | request a new link |
| `403 Invalid signature.` on a reset/verify link | the link was generated for a different host than the one serving it | fix `APP_URL`; links are host-bound |
| `429` on login | more than 5 attempts for the same email + IP in a minute | wait 60s, or raise `LOGIN_RATE_LIMIT_PER_MINUTE` |
| permission granted directly is missing from `/me` | the relation was loaded before the grant | `unsetRelation('directPermissions')`, or just re-request |

---

## 9. Out of scope

Module 01 provides **no** functionality for students, admissions, enrollment, subjects,
results, promotion, attendance, timetables or fees. No placeholder models, policies,
migrations or routes exist for them, and no future business permission is seeded. The
authorization layer is built so those modules can be added without touching it.
