# Module 02 — School Configuration & Academic Foundation: Audit

**Project:** `school-system-api` (Headless School Management API)
**Audit date:** 2026-09-27
**Auditor:** Module 02 implementation agent
**Scope:** Read-only audit. No application code was modified during this phase.
**Prerequisite:** Module 01 — Authentication & Authorization (complete, 87 tests green).
Read `module-01-authentication-authorization-audit.md` and
`module-01-authentication-authorization-final.md` before this document.

---

## 0. Executive summary

Module 01 delivered a complete, stateless, token-based authentication and role/permission
authorization layer. **None of the tables required by Module 02 exist yet** — there is no
`schools`, `academic_sessions`, `terms`, `class_levels`, `classes` or `sections` table,
and no academic model, enum, service, resource, route, test or permission.

Consequently:

- There is **nothing to preserve and nothing to reconcile** in the academic domain. The
  audit's earlier conclusion ("greenfield build") still holds for Module 02.
- There are **no multi-tenant artefacts** anywhere in the codebase. `school_id`,
  `tenant_id`, `school_branch_id` and tenant middleware do not exist and are not
  required. The single-school constraint is therefore a *design* constraint to enforce
  deliberately, not a legacy problem to unpick.
- The work is to build six new tables, six models, four enums, five services, six API
  resources, ~14 Form Requests, seven controllers and 30 endpoints, following Module 01's
  conventions exactly.

**Three findings require a decision before implementation** — see §3 (C1, C2, C3):

1. **Module 01's response envelope cannot express pagination.** `ApiResponse::success()`
   wrapping a `LengthAwarePaginator` produces `{"data":{"current_page":…,"data":[…]}}` — a
   nested `data.data`. An additive `ApiResponse::paginated()` is required.
2. **`Class` is a PHP reserved word.** `App\Models\Class` is a parse error, so the model
   must be `SchoolClass` while the table stays the natural `classes`.
3. **The "only one active X" rules cannot be expressed with a plain unique index**, and the
   project supports four database drivers, so driver-specific partial-index SQL must be
   avoided. A portable nullable-marker column is required. See §4.4.

---

## A. Existing architecture

### A.1 Platform

| Item | Value | Source |
|---|---|---|
| Framework | Laravel `12.69.2` | `composer.json` |
| PHP | `8.2.12` (ZTS) | `php -v` |
| Token package | `laravel/sanctum` `^4.3` | `composer.json` |
| Test runner | Pest `^3.8` + `pest-plugin-laravel` | `composer.json` |
| Formatter | `laravel/pint` `^1.24` | `composer.json` |
| Extra RBAC package | **none** | `vendor/` — no `spatie/laravel-permission` |
| Databases configured | `sqlite`, `mysql`, `pgsql`, `sqlsrv` | `config/database.php:35,47,87,106` |
| Dev database | SQLite file | `.env` → `DB_CONNECTION=sqlite` |
| Test database | SQLite `:memory:` | `phpunit.xml` |

**Architectural consequence:** every schema change must be expressible through Laravel's
portable `Blueprint` API. No `CHECK` constraints, no driver-specific partial indexes, no
raw DDL. This constraint drives the target design in §4.

### A.2 API conventions (Module 01)

| Concern | Convention | Where |
|---|---|---|
| Route prefix | `/api/v1` | `bootstrap/app.php:25` (`apiPrefix: 'api/v1'`) |
| Route file | `routes/api.php`, single file | `bootstrap/app.php:24` |
| Route names | dotted, `auth.` prefix | `routes/api.php:21` |
| Auth guard | `auth:api` (Sanctum) | `config/auth.php` guards |
| Account state | `active` middleware on every authed route | `routes/api.php:63` |
| Success envelope | `{"data": …, "message": "…"}` | `app/Support/ApiResponse.php:25` |
| Failure envelope | `{"message": "…", "errors"?: {field:[…]}}` | `app/Support/ApiResponse.php:42` |
| Error mapping | `401`/`403`/`404`/`422`/`429` + generic `500` | `bootstrap/app.php:57-110` |
| `APP_DEBUG` safety | generic `500` regardless of debug | `bootstrap/app.php:99` |
| No web redirects | `redirectGuestsTo(fn () => null)` | `bootstrap/app.php:55` |
| Global throttle | `throttle:api` prepended to the `api` group | `bootstrap/app.php:35` |
| User representation | `UserResource`, explicit whitelist | `app/Http/Resources/UserResource.php` |
| Model exposure | raw models are **never** returned | established in Module 01 |
| Controllers | extend abstract `App\Http\Controllers\Controller`, stay thin | `app/Http/Controllers/Controller.php` |
| Services | `App\Services\Auth\AuthenticationService` | `app/Services/Auth/AuthenticationService.php` |
| Business rules | live in the service, not in Form Requests or controllers | `PasswordRule`, `AuthenticationService` |
| Tests | Pest, `tests/Feature`, `RefreshDatabase` | `tests/Pest.php:23` |

### A.3 Authorization conventions (Module 01)

| Concern | Convention | Where |
|---|---|---|
| Permission shape | `<module>.<action>`, lower snake case, regex-validated | `app/Models/Permission.php:57` |
| Permission resolution | Gate ability by name; DB-driven, no compiled list | `app/Providers/AuthServiceProvider.php:69` |
| Super Admin bypass | one `Gate::before` → `true` | `app/Providers/AuthServiceProvider.php:53` |
| Role bypass | roles are *also* a Gate ability, so bypass covers `role:` middleware | `AuthServiceProvider.php:40` |
| Middleware aliases | `active`, `role`, `permission`, `verified` | `bootstrap/app.php:39-44` |
| Role checks in code | **forbidden** — always ask the Gate | `AuthServiceProvider` docblock |
| Permission union | role permissions **∪** direct user grants | `app/Models/User.php:201` (`Collection::union`) |
| Late-seeded permissions | enforced with no code change | proven by `AuthorizationTest` |

**Consequence for Module 02:** new permissions are seeded rows. There is no code change,
no Gate definition, and no middleware change required. `permission:classes.view` works the
moment the row exists and is attached to a role.

### A.4 Enum conventions (Module 01)

All three existing enums follow an identical shape — `app/Enums/`:

```php
enum Role: string
{
    case SUPER_ADMIN = 'SUPER_ADMIN';
    // …

    public function values(): array   // static helper returning list<string>
}
```

- **Backed by `string`**, and the stored value **equals the case name** (`'ACTIVE'`,
  `'SUPER_ADMIN'`). This makes the database human-readable and keeps debugging trivial.
- A behavioural helper is added only when it carries real logic
  (`UserStatus::isActive()`, `Role::isSuperAdmin()`).
- A static `values()` helper exists on all three.
- Enums are **cast on the model** (`'status' => UserStatus::class`), so controllers
  compare enum instances, never strings.
- No enum implements an interface or uses a trait. Keep it plain.

`app/Enums/StaffType.php:4` also documents the project's stance on classification vs
role, which is directly relevant to §D.

### A.5 Migration conventions (Module 01)

- Anonymous class migrations: `return new class extends Migration`.
- Timestamps `2026_09_27_16000X_description` (Module 01 block ends at `160005`).
- `Schema::create` with a snake_case plural table name.
- Columns ordered `id`, attributes, `timestamps()`.
- `foreignId(...)->constrained()` for FKs; `->index()` added explicitly where the column
  is not the leading column of an index.
- **Explicit `onDelete` behaviour on every FK** — `restrictOnDelete()` for
  `users.role_id`, `cascadeOnDelete()` for the permission pivots and
  `staff.user_id`. Never left to the database default.
- `down()` is implemented and reverses `up()` (drops FKs before columns).
- Migrations are **additive**; the original `0001_01_01_000000_create_users_table.php`
  was never edited.
- Docblocks explain *why* a decision was made, not *what* the code does.

### A.6 Seeder conventions (Module 01)

- `DatabaseSeeder` calls a list of seeders; it uses `WithoutModelEvents`.
- Seeders are **idempotent** via `updateOrCreate` keyed on a natural key
  (`roles.name`, `permissions.name`).
- Role→permission mapping uses `sync()` (full replacement) so re-running converges.
- Every seeder carries a docblock explaining its scope and its safety guard.
- `SuperAdminSeeder` is guarded by `app()->environment(['local','testing'])` and never
  hardcodes a password.

### A.7 Test conventions (Module 01)

- Pest functional style. `tests/Feature/…` for HTTP tests, `tests/Unit/…` for pure logic.
- `Tests\TestCase::setUp()` seeds `RoleSeeder` + `PermissionSeeder` so every feature test
  has a known authorization baseline (`tests/TestCase.php:19`).
- Shared helpers live in `tests/Pest.php`, not in a trait: `loginAs()`, `userWithRole()`,
  `unverifiedUser()`, `grantPermission()`, `forgetResolvedUser()`.
- **Authorization middleware is tested against routes registered inside the test**
  (`registerAuthorizationTestRoutes()`), so no placeholder production endpoint is created
  for a module that does not exist. Module 02 must follow this discipline.
- `forgetResolvedUser()` is called between requests that mutate the authenticated user,
  because the auth manager caches the resolved user per guard within one test process.
- Model factories exist for every model that tests need to build indirectly.
- Multi-request flows (logout, suspension, password reset) are tested, not just
  single-request happy paths.

### A.8 Current route inventory

`php artisan route:list --path=api` → 7 routes, all Module 01 auth:

```
POST   api/v1/auth/login
POST   api/v1/auth/logout
GET    api/v1/auth/me
POST   api/v1/auth/forgot-password
POST   api/v1/auth/reset-password
GET    api/v1/auth/email/verify/{id}/{hash}
POST   api/v1/auth/email/verification-notification
```

There is **no** `GET /api/v1/school`, no academic routes, and no index route at the API
root. Module 02 adds ~30 routes and must not disturb these seven.

---

## B. Existing database

### B.1 Table inventory

All 10 migrations are `Ran` (batch 1). The 13 resulting tables:

| Table | Origin | Module |
|---|---|---|
| `users` | `0001_01_01_000000` + `2026_09_27_160004` | framework + M01 |
| `password_reset_tokens` | `0001_01_01_000000` | framework |
| `sessions` | `0001_01_01_000000` | framework (unused by the API) |
| `cache`, `cache_locks` | `0001_01_01_000001` | framework |
| `jobs`, `job_batches`, `failed_jobs` | `0001_01_01_000002` | framework |
| `personal_access_tokens` | `2026_09_27_155427` | M01 (Sanctum) |
| `roles` | `2026_09_27_160000` | M01 |
| `permissions` | `2026_09_27_160001` | M01 |
| `permission_role` | `2026_09_27_160002` | M01 |
| `permission_user` | `2026_09_27_160003` | M01 |
| `staff` | `2026_09_27_160005` | M01 |

### B.2 Required Module 02 tables — none exist

| Required table | Exists? | Evidence |
|---|---|---|
| `schools` | **NO** | not in `database/migrations/`, not in the table list |
| `academic_sessions` | **NO** | not in `database/migrations/`, not in the table list |
| `terms` | **NO** | not in `database/migrations/`, not in the table list |
| `class_levels` | **NO** | not in `database/migrations/`, not in the table list |
| `classes` | **NO** | not in `database/migrations/`, not in the table list |
| `sections` | **NO** | not in `database/migrations/`, not in the table list |

### B.3 Required Module 02 columns on existing tables — none needed

| Required column | Exists? |
|---|---|
| `users.school_id` | **NO — and must not be added.** See §C2. |
| any academic FK on `users` | **NO — and must not be added.** Staff/subject links belong to their own modules. |

### B.4 Multi-tenancy audit

An explicit search for tenant concepts across `app/`, `database/`, `config/` and
`routes/` finds **zero** occurrences of `school_id`, `tenant_id`, `school_branch_id`,
`branch_id`, or any tenant/school-switching middleware.

| Tenant artefact | Present? |
|---|---|
| `school_id` column | no |
| `tenant_id` column | no |
| `school_branch_id` / `branch_id` | no |
| tenant resolver / scoping trait | no |
| `EnsureTenant` / `SchoolScope` middleware | no |
| school selection endpoint | no |
| school subscription model | no |
| global scope adding a school filter | no |

**Conclusion:** the database is not multi-tenant and the module must not make it so. The
`schools` table is a *configuration singleton*, not a parent row that other tables
reference for tenancy. See §C2 for why no table gets a `school_id`.

### B.5 Current `users` shape (relevant subset)

```
id                  bigint PK
name                varchar(255)
email               varchar(255) UNIQUE
email_verified_at   timestamp NULL
password            varchar(255)
remember_token      varchar(100) NULL
role_id             bigint NULL  FK → roles.id  ON DELETE RESTRICT
status              varchar(255) DEFAULT 'ACTIVE'  INDEX
last_login_at       timestamp NULL
created_at / updated_at
```

---

## C. Existing conflicts

| # | Severity | Finding |
|---|---|---|
| C1 | **HIGH** | **`ApiResponse` cannot express pagination.** `success()` builds `['data' => $data]` and serialises a `LengthAwarePaginator` through `JsonSerializable`, which yields `{"data":{"current_page":1,"data":[…],"total":15,…}}`. A client would read items at `data.data`. Module 02 introduces the first paginated collection endpoints, so this must be resolved **before** any controller is written. Resolution: add an additive `ApiResponse::paginated()` producing `{"data":[…],"meta":{…},"links":{…}}` — items stay at `data`, pagination moves to `meta`, so the Module 01 envelope is preserved rather than forked. |
| C2 | **HIGH (constraint on design, not a defect)** | **The single-school rule must be enforced without tenancy.** Nothing prevents a future developer from adding `school_id` to every table and turning this into a multi-school system. Mitigation is threefold: (a) no table in this module gets a `school_id` column; (b) `schools` is a singleton with a database-level uniqueness guarantee on the active record; (c) the design rationale is documented in code and in `docs/api/school-configuration.md` so a later reader understands the omission is deliberate. There is currently no documentation warning against re-introducing tenancy. |
| C3 | **MEDIUM** | **`Class` is a PHP reserved word.** `App\Models\Class` is a parse error; `use App\Models\Class;` is a syntax error. The model must be `SchoolClass`. The table name `classes` is unaffected (`class` is reserved in some SQL dialects, `classes` is not). Also note `resolve()` on a model named `SchoolClass` is fine, but the route parameter and resource name must stay unambiguous (`{class}` in the route is fine; the resource is `SchoolClassResource`). |
| C4 | **MEDIUM** | **"Only one active X" is not enforceable with a plain unique index.** `UNIQUE (status)` would allow exactly one `ACTIVE` *and* exactly one `COMPLETED` — wrong. A partial index `WHERE status='ACTIVE'` is SQLite/PostgreSQL syntax; MySQL 8 needs a *functional* index with different syntax; SQL Server cannot do it at all. With four drivers configured, driver-specific DDL in a migration is unacceptable. Resolution: a nullable `active_marker` column with a plain `UNIQUE` index (see §4.4), maintained automatically by the model so it cannot drift from `status`. |
| C5 | **LOW** | **`PermissionSeeder` uses `sync()` on roles.** Adding Module 02 permissions in a *separate* seeder with `sync()` would silently revoke every Module 01 grant. Resolution: the new seeder must use `syncWithoutDetaching()`, and the test baseline in `tests/TestCase.php` must include it. Documented in §E. |
| C6 | **LOW** | **No API documentation index exists.** `docs/api/` contains only `authentication.md`. With two modules shipping, a reader has no entry point. Module 01 established no index, so creating one is optional rather than required; Module 02 will add `docs/api/README.md` because two documents without an index is a real navigation cost. |
| C7 | **LOW** | **`StaffFactory` defaults `user_id => null`** although the column is `NOT NULL` and `unique()`. Harmless today because every test supplies a `user_id` via `UserFactory::staff()`, but a direct `StaffFactory::create()` would fail. Pre-existing; not Module 02's responsibility, and Module 02 adds no `staff` interaction. Noted so it is not mistaken for a new regression. |
| C8 | **OK (verified good)** | **No duplicate academic concepts.** No `class_levels`/`grades`/`streams` redundancy, no `exam_years` synonym for `academic_sessions`, no `class_arms` synonym for `sections`. The clean-slate naming in §D is free to be canonical. |
| C9 | **OK (verified good)** | **No incorrect relationships to repair.** `users → roles`, `roles ⇄ permissions`, `users ⇄ permissions`, `users → staff` are all correct as built. |
| C10 | **OK (verified good)** | **Status handling is consistent.** `UserStatus` (`ACTIVE`/`INACTIVE`/`SUSPENDED`) is distinct from the `status` column on the `staff` table, which does not exist — `staff` uses `staff_type` only. No conflation of a person's account state with a record's lifecycle state. Module 02 introduces *record lifecycle* statuses and must not reuse `UserStatus`: suspending a class is not the same as suspending a person. |
| C11 | **OK (verified good)** | **Naming is clean and consistent.** Lower snake case tables, singular PascalCase models, dotted permission names. `classes`/`sections`/`terms` match the domain language in the requirements. |
| C12 | **OK (verified good)** | **Constraint and index discipline is already the house style.** Every existing table has explicit `onDelete` behaviour and an explicit index on every filtered column. Module 02 must match this, not merely add FKs. |

---

## D. Target architecture

### D.1 Design principles

1. **Portable schema only.** Every constraint must be expressible via Laravel's
   `Blueprint`. No raw DDL, no driver branching.
2. **The database is the last line of defence, the service is the first.**
   Every business rule is enforced in a service inside a transaction *and* backed by a
   DB constraint where one is possible. A raw `INSERT` bypassing the API still cannot
   produce an invalid state.
3. **Singletons are singletons in the schema.** "One school", "one active session",
   "one active term" are database facts, not application conventions.
4. **Historical records are archived, never casually deleted.** Future modules (enrollment,
   results, promotion, report cards) will reference these rows. `ARCHIVED` is a first-class
   status.
5. **No tenancy.** No `school_id` anywhere. See §C2.
6. **Term numbers are integers.** Ordering must not depend on parsing the string
   `"Second Term"`.

### D.2 Entity relationship

```
                        ┌─────────────────┐
                        │     schools     │  singleton configuration
                        │  (no school_id  │  no other table references it
                        │   on anything)  │
                        └─────────────────┘

  TIME DIMENSION (per school year)          STRUCTURE DIMENSION (standing)

  ┌────────────────────┐                    ┌────────────────┐
  │ academic_sessions  │                    │  class_levels  │
  │  name     UNIQUE   │                    │ code   UNIQUE  │
  │  start_date <      │                    │ name   UNIQUE  │
  │    end_date        │                    │ sort_order     │
  │  status  INDEXED   │                    │ status         │
  │  active_marker     │                    └───────┬────────┘
  │         UNIQUE ◄───┼── only one ACTIVE           │ 1:N
  └─────────┬──────────┘                            ▼
            │ 1:N                        ┌────────────────────┐
            │                            │  classes           │
            │                            │ class_level_id FK  │  RESTRICT
            ▼                            │  (name,code)       │  UQ per level
  ┌────────────────────┐                  │ sort_order, status │
  │       terms        │                  └─────────┬──────────┘
  │ academic_session_id│  RESTRICT                   │ 1:N
  │  term_number       │  UQ per session             ▼
  │  start < end       │                  ┌────────────────────┐
  │  status   INDEXED  │                  │     sections       │
  │  active_marker     │                  │ school_class_id FK │  RESTRICT
  │         UNIQUE ◄───┼── only one ACTIVE │  (name,code)      │  UQ per class
  └────────────────────┘   AND its session └────────────────────┘
                            must be the ACTIVE session
```

The two dimensions are deliberately **independent**. A session is a period of time; a
class level is a permanent stage. They are not connected, and neither is connected to
`schools`. `Academic Session ≠ Class Level ≠ Class ≠ Section` — four distinct concepts,
four tables, no shortcut.

### D.3 Table specifications

#### `schools` — singleton configuration

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint PK | | |
| `name` | varchar(150) | no | |
| `short_name` | varchar(50) | no | used in report headers |
| `motto` | varchar(255) | yes | |
| `email` | varchar(255) | yes | school-wide address, not a user account |
| `phone` | varchar(30) | yes | |
| `alternate_phone` | varchar(30) | yes | |
| `website` | varchar(255) | yes | |
| `address_line1` | varchar(255) | yes | |
| `city` | varchar(120) | yes | |
| `state` | varchar(120) | yes | |
| `country` | varchar(120) | yes | |
| `principal_name` | varchar(150) | yes | |
| `registration_number` | varchar(100) | yes | the school's government registration |
| `status` | varchar(20) | no, default `ACTIVE` | `SchoolStatus` |
| `active_marker` | boolean | **yes** | `1` for the current school, `NULL` otherwise. **`UNIQUE`** — see §4.4 |
| timestamps | | | |

**Deliberately omitted from the suggested field list:**

| Field | Why omitted |
|---|---|
| `logo_path`, `favicon_path` | The project has **no file upload, media handling or storage abstraction** of any kind. Storing a path with no way to set it is dead weight, and inventing an upload endpoint would exceed this module's scope. |
| `address` (single field) | Split into `address_line1` / `city` / `state` / `country` so reporting and future letterheads can filter by city or country. |
| a generic key/value `settings` table | See §D.5. |

`registration_number` is **not** unique — two schools in different countries may share a
format, and a unique index would block legitimate data. The singleton guarantee is
`active_marker`.

#### `academic_sessions`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint PK | | |
| `name` | varchar(50) | no | `2026/2027` — **`UNIQUE`** |
| `start_date` | date | no | |
| `end_date` | date | no | > `start_date` |
| `status` | varchar(20) | no, default `UPCOMING` | `AcademicSessionStatus` |
| `active_marker` | boolean | yes | **`UNIQUE`** — at most one `ACTIVE` session |
| timestamps | | | |

Indexes: `UNIQUE(name)`, `UNIQUE(active_marker)`, `INDEX(status)`, `INDEX(start_date)`.

#### `terms`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint PK | | |
| `academic_session_id` | bigint FK | no | → `academic_sessions.id`, **`restrictOnDelete`** |
| `name` | varchar(100) | no | display label, e.g. "First Term" |
| `term_number` | unsigned tinyint | no | `1`, `2`, `3` — the ordering key |
| `start_date` | date | no | within the session's window |
| `end_date` | date | no | within the session's window, > `start_date` |
| `status` | varchar(20) | no, default `UPCOMING` | `TermStatus` |
| `active_marker` | boolean | yes | **`UNIQUE`** — at most one `ACTIVE` term system-wide |
| timestamps | | | |

Constraints: `UNIQUE(academic_session_id, term_number)`, `UNIQUE(active_marker)`,
`INDEX(status)`.

`term_number` is a **number, not a string**. `name` is presentation only and is never
used for ordering. This directly satisfies "Do not store only `First Term` as an
arbitrary string if the system needs reliable ordering."

#### `class_levels`

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint PK | | |
| `name` | varchar(100) | no | `Nursery`, `Primary`, … — **`UNIQUE`** |
| `code` | varchar(20) | no | `NURSERY`, `PRIMARY`, … — **`UNIQUE`**, uppercased on write |
| `sort_order` | smallint | no, default 0 | |
| `status` | varchar(20) | no, default `ACTIVE` | `CatalogStatus` |
| timestamps | | | |

Values are **data, not code** — seeded rows, never hardcoded into controllers. The four
stages in the requirements are seed data; the system is not limited to them.

#### `classes` (model `SchoolClass`)

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint PK | | |
| `class_level_id` | bigint FK | no | → `class_levels.id`, **`restrictOnDelete`** |
| `name` | varchar(100) | no | `Primary 1` — `UNIQUE(class_level_id, name)` |
| `code` | varchar(20) | no | `PRI1` — `UNIQUE(class_level_id, code)` |
| `sort_order` | smallint | no, default 0 | |
| `status` | varchar(20) | no, default `ACTIVE` | `CatalogStatus` |
| timestamps | | | |

**Uniqueness is scoped to the parent class level**, not global. A class is only ever
identified within its level, which is the relationship that owns it. Scoping avoids
colliding if the school ever runs two parallel streams of the same level, and it keeps
the unique key aligned with the FK. No student, subject or teacher column exists here —
those belong to later modules.

#### `sections` — class-specific (see D.4)

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | bigint PK | | |
| `school_class_id` | bigint FK | no | → `classes.id`, **`restrictOnDelete`** |
| `name` | varchar(50) | no | `A`, `B`, `C` — `UNIQUE(school_class_id, name)` |
| `code` | varchar(20) | no | `A` — `UNIQUE(school_class_id, code)` |
| `sort_order` | smallint | no, default 0 | |
| `status` | varchar(20) | no, default `ACTIVE` | `CatalogStatus` |
| timestamps | | | |

### D.4 Two design decisions that need justification

#### D.4.1 Sections are class-specific, not global

The requirements raise this explicitly: *"think carefully about whether sections are
globally reusable or class-specific."*

**Decision: class-specific, via a direct `school_class_id` foreign key.**

| Option | Verdict |
|---|---|
| Global `sections` + `section_class` pivot | Rejected. It models a reusable entity the school does not have. Section `A` in `Primary 5` and section `A` in `JSS 1` are unrelated rows that merely share a name; a pivot implies a shared identity that means nothing. It also doubles the queries for every future module that needs "the section of this enrollment." |
| Class-specific `sections` with FK | **Chosen.** `Primary 5 → [A, B, C]` and `JSS 1 → [A, B, C]` are six independent rows. Ordering is per class. Lookup is one index seek. No global uniqueness is imposed on the name, exactly as required. |

A direct FK also means `Primary 5A` is addressed unambiguously, and deleting a class is
naturally blocked while sections exist — which is the deletion guard the requirements ask
for in Phase 21.

#### D.4.2 "Only one active X" via a nullable marker column

The requirement: *"The system must prevent `2026/2027 = ACTIVE` and `2027/2028 = ACTIVE`
at the same time."* This must hold even against a concurrent request or a raw insert.

Rejected approaches:

| Approach | Why rejected |
|---|---|
| `UNIQUE (status)` | Would permit exactly one `ACTIVE` **and** exactly one `COMPLETED`, then reject a second completed session. Semantically wrong. |
| Partial unique index `WHERE status='ACTIVE'` | SQLite and PostgreSQL support it; MySQL 8 requires *functional index* syntax (`((CASE …))`); SQL Server does not support it at all. Four drivers are configured. |
| Application check only | Racy: two concurrent activations can both read "none active" and both write. |

**Chosen: a nullable `active_marker` boolean with a plain `UNIQUE` index.**

```
active_marker = 1     → the one current/active record
active_marker = NULL  → every other record
```

All of SQLite, MySQL, PostgreSQL and SQL Server treat `NULL` as distinct in a unique
index, so this yields "at most one active row" with zero driver-specific SQL. It is
expressed purely through `$table->boolean('active_marker')->nullable()->unique()`.

**Drift is impossible** because the marker is never client-supplied. It is derived from
`status` by a `saving` hook on the model, so `status = ACTIVE` always implies
`active_marker = 1`, whichever code path set the status. The column is excluded from
`$fillable` and from every API Resource, so it is an implementation detail of the
constraint rather than part of the contract.

The service additionally performs the check inside a transaction with a row lock, which
turns a would-be constraint violation into a clear `422` for the user.

### D.5 School settings: strongly-typed columns, no key-value store

The requirements warn against a generic settings table "merely for abstraction," and the
project has no such precedent.

**Decision: no `settings` table.** Every school setting that Module 02 can justify is a
dedicated, strongly-typed, nullable column on `schools` (see D.3). Consequences:

- `School::first()->email` is typed and IDE-discoverable; a key-value store would make
  every consumer cast a string.
- No `json_encode`/`json_decode` in resource output, so the API contract is stable and
  greppable.
- A new setting is an additive nullable column, not a magic string.

Explicitly **not** added: result display settings, report card settings, grading scales.
Those are result/report-card concerns and belong to the modules that own them. Adding
them now would be speculative.

### D.6 Enums

Four enums, following the Module 01 shape exactly (string-backed, value equals case name,
`values()` helper, behavioural helpers only where they earn their place):

| Enum | Cases | Used by |
|---|---|---|
| `SchoolStatus` | `ACTIVE`, `INACTIVE` | `schools.status` |
| `AcademicSessionStatus` | `UPCOMING`, `ACTIVE`, `COMPLETED` | `academic_sessions.status` |
| `TermStatus` | `UPCOMING`, `ACTIVE`, `COMPLETED` | `terms.status` |
| `CatalogStatus` | `ACTIVE`, `INACTIVE`, `ARCHIVED` | `class_levels`, `classes`, `sections` |

**Deviation from the brief, deliberately:** the brief lists `ClassLevelStatus`,
`ClassStatus` and `SectionStatus` separately. Three enums with identical cases and
identical semantics would be pure duplication — changing the archive policy would mean
editing three files, and a `status` string would be ambiguous about which enum it wanted.
One shared `CatalogStatus` is used instead, which is exactly the "do not create an enum
merely because one string field exists" caution applied in the other direction. This is
recorded in the final report.

`SchoolStatus` is **not** `UserStatus`. `UserStatus` (`ACTIVE`/`INACTIVE`/`SUSPENDED`)
describes a *person's account*; `SUSPENDED` is an authentication concept. `SchoolStatus`
describes a *record's lifecycle*. The two must not be conflated — see §C10.

### D.7 Business rules and where each is enforced

| # | Rule | Service | DB constraint | Test |
|---|---|---|---|---|
| 1 | Session `name` is unique | — | `UNIQUE(name)` | ✓ |
| 2 | At most one `ACTIVE` session | `AcademicSessionService::activate` | `UNIQUE(active_marker)` | ✓ |
| 3 | `start_date < end_date` | `AcademicSessionService` | — (portable; see D.1) | ✓ |
| 4 | Historical sessions are never casually deleted | `AcademicSessionService::delete` (refuses when terms exist) | FK `restrictOnDelete` | ✓ |
| 5 | A term belongs to exactly one session | — | FK `NOT NULL` | ✓ |
| 6 | `term_number` unique within a session | — | `UNIQUE(academic_session_id, term_number)` | ✓ |
| 7 | Term dates inside the session window | `TermService` | — | ✓ |
| 8 | At most one `ACTIVE` term | `TermService::activate` | `UNIQUE(active_marker)` | ✓ |
| 9 | The active term belongs to the active session | `TermService::activate` | — | ✓ |
| 10 | Exactly one current school | `SchoolConfigurationService` (no create endpoint) | `UNIQUE(active_marker)` | ✓ |
| 11 | Class level `code` unique | — | `UNIQUE(code)` | ✓ |
| 12 | A class belongs to a class level | — | FK `NOT NULL` | ✓ |
| 13 | Class `(name, code)` unique within its level | — | `UNIQUE(class_level_id, …)` | ✓ |
| 14 | Section `(name, code)` unique within its class; not globally | — | `UNIQUE(school_class_id, …)` | ✓ |
| 15 | Cannot delete a record that has children | `AcademicStructureService` | FK `restrictOnDelete` (backstop) | ✓ |
| 16 | Cannot delete the current session / current term | `AcademicSessionService`, `TermService` | — | ✓ |

Rules 1, 5, 6, 10–14 are **fully** database-enforced. Rules 2, 7–9, 15, 16 are enforced
in a service **and** backed by the database where possible.

### D.8 Current academic context

`AcademicContextService` is the single answer to "what is current?" — the requirement
that no future module should independently run `WHERE status = 'ACTIVE'`.

```php
$context = app(AcademicContextService::class);

$context->currentSession();  // ?AcademicSession
$context->currentTerm();     // ?Term
$context->hasActiveContext(); // bool
$context->forget();          // clear memoisation after a write
```

Registered as a **container singleton** so it memoises per request and never re-queries.
No cross-request cache: a cache would be a correctness risk immediately after an
activation. Activation calls `forget()` so subsequent reads in the same request see the
new state.

Invariant enforced by `TermService::activate()`: *the active term's session is the
active session.* `GET /api/v1/academic-context` returns both, and the service can assert
the relationship rather than trusting the caller.

### D.9 Authorization

Reuses Module 01 exactly: permissions are seeded rows, enforced by the existing
`permission:` middleware. No new mechanism, no role check in a controller.

24 permissions, chosen by reviewing each of the brief's suggestions against the endpoints
actually implemented:

| Group | Permissions |
|---|---|
| `school` | `view`, `update` |
| `academic_sessions` | `view`, `create`, `update`, `delete`, `activate` |
| `terms` | `view`, `create`, `update`, `delete`, `activate` |
| `class_levels` | `view`, `create`, `update`, `delete` |
| `classes` | `view`, `create`, `update`, `delete` |
| `sections` | `view`, `create`, `update`, `delete` |

**Rejected from the brief's list:** none are dropped, but `school.create` /
`school.delete` are deliberately **never created** — the endpoints must not exist in a
single-school system, so a permission for them would be a lie. `academic_sessions.activate`
and `terms.activate` are kept as separate permissions from `update` because "switch the
school year" is a distinctly more dangerous operation than editing a label, and a school
may reasonably want one registrar to edit terms but not to activate them.

Role assignment (a starting point, not a policy for future modules):

| Role | Module 02 access | Rationale |
|---|---|---|
| `SUPER_ADMIN` | all 24 (via the existing Gate bypass) | Already bypasses everything; seeded for explicitness. |
| `ADMIN` | all except `academic_sessions.delete`, `terms.delete` | Runs configuration day to day. May not destroy academic history. |
| `REGISTRAR` | every `*.view` + `terms.create`, `terms.update` | Operates the term calendar and reads the academic structure. No activation, no deletion, no school editing. |
| `STAFF` | every `*.view` | Read-only academic context at this stage. |
| `STUDENT` | **none** | No configuration management. |

`STAFF` and `STUDENT` receive read access so the structure is usable, but no write
permission of any kind — an authenticated user being able to change the academic year is
exactly the privilege-escalation the brief warns about.

### D.10 Endpoints

```
School           GET    /api/v1/school
                 PUT    /api/v1/school

Academic context GET    /api/v1/academic-context

Sessions         GET    /api/v1/academic-sessions
                 POST   /api/v1/academic-sessions
                 GET    /api/v1/academic-sessions/{academicSession}
                 PUT    /api/v1/academic-sessions/{academicSession}
                 DELETE /api/v1/academic-sessions/{academicSession}
                 POST   /api/v1/academic-sessions/{academicSession}/activate

Terms            GET    /api/v1/academic-sessions/{academicSession}/terms
                 POST   /api/v1/academic-sessions/{academicSession}/terms
                 GET    /api/v1/terms/{term}
                 PUT    /api/v1/terms/{term}
                 DELETE /api/v1/terms/{term}
                 POST   /api/v1/terms/{term}/activate

Class levels     GET|POST           /api/v1/class-levels
                 GET|PUT|DELETE     /api/v1/class-levels/{classLevel}

Classes          GET|POST           /api/v1/classes
                 GET|PUT|DELETE     /api/v1/classes/{schoolClass}

Sections         GET|POST           /api/v1/sections
                 GET|PUT|DELETE     /api/v1/sections/{section}
```

**Deviations from the brief, and why:**

- **No `POST /api/v1/schools`, no `DELETE /api/v1/schools/{id}`.** The brief forbids
  them. A second school record cannot be created through the API at all.
- **No `GET /academic-sessions/{session}/terms/{term}`.** The brief lists term show/update
  as flat `/terms/{term}` routes. Keeping them flat removes any possibility of a term from
  a different session being reached through a session-scoped path, which is an IDOR
  class of bug. Index and store stay nested, because they are inherently session-scoped.
- **`GET /academic-context` is added.** The brief requires a single mechanism for
  "current session / current term" in Phase 7. A client needs to read it; exposing the
  service with no endpoint would leave the value unreachable over HTTP.
- **No `PATCH`, no soft deletes.** `PUT` matches Module 01's style; `DELETE` is a real
  delete guarded by service rules, and `ARCHIVED` is reachable through `PUT`.

`scopeBindings()` is applied to the session group as defence in depth, so a term in the
nested routes can never resolve against the wrong session.

### D.11 Filtering, pagination, response

| Endpoint | Filters |
|---|---|
| `GET /academic-sessions` | `status`, `search`, `sort` (`start_date`\|`name`), `direction`, `per_page` |
| `GET /academic-sessions/{s}/terms` | `status`, `sort` (`term_number`\|`start_date`), `direction`, `per_page` |
| `GET /class-levels` | `status`, `search`, `sort` (`sort_order`\|`name`), `direction`, `per_page` |
| `GET /classes` | `class_level_id`, `status`, `search`, `sort` (`sort_order`\|`name`), `direction`, `per_page` |
| `GET /sections` | `school_class_id`, `status`, `search`, `sort` (`sort_order`\|`name`), `direction`, `per_page` |

`per_page` is capped at 100 and defaults to 15. Only an allow-listed `sort` column is
accepted — never a raw user-supplied column name, which would be an injection surface.

Pagination uses the new `ApiResponse::paginated()` so items stay at `data` and the
Module 01 envelope is preserved (see §C1).

### D.12 Deletion semantics

| Record | `DELETE` allowed when | Otherwise |
|---|---|---|
| School | never — no endpoint | — |
| Academic session | no terms exist, and it is not the current session | `422` |
| Term | it is not the current term, and its session has no other active term | `422` |
| Class level | no classes exist | `422` |
| Class | no sections exist | `422` |
| Section | never blocked beyond `status` | — |

Every refusal is a clean `422` with a specific message — never a raw
`QueryException` leaking a constraint name, and never a `500`. Deactivation via
`PUT {"status": "INACTIVE"}` / `"ARCHIVED"` is the normal way to retire a record.

---

## E. Migration plan

Six **new** migrations. **No existing migration is modified** — the Module 01
`create_users_table` stays untouched, and every new table is `Schema::create`.

| # | File | Creates | Key constraints |
|---|---|---|---|
| 1 | `2026_09_27_170000_create_schools_table.php` | `schools` | `UNIQUE(active_marker)` |
| 2 | `2026_09_27_170001_create_academic_sessions_table.php` | `academic_sessions` | `UNIQUE(name)`, `UNIQUE(active_marker)`, `INDEX(status)`, `INDEX(start_date)` |
| 3 | `2026_09_27_170002_create_terms_table.php` | `terms` | FK → `academic_sessions` `restrictOnDelete`, `UNIQUE(academic_session_id, term_number)`, `UNIQUE(active_marker)`, `INDEX(status)` |
| 4 | `2026_09_27_170003_create_class_levels_table.php` | `class_levels` | `UNIQUE(name)`, `UNIQUE(code)`, `INDEX(sort_order)` |
| 5 | `2026_09_27_170004_create_classes_table.php` | `classes` | FK → `class_levels` `restrictOnDelete`, `UNIQUE(class_level_id, name)`, `UNIQUE(class_level_id, code)`, `INDEX(sort_order)` |
| 6 | `2026_09_27_170005_create_sections_table.php` | `sections` | FK → `classes` `restrictOnDelete`, `UNIQUE(school_class_id, name)`, `UNIQUE(school_class_id, code)`, `INDEX(sort_order)` |

Ordering: `schools` (independent) → `academic_sessions` → `terms` (needs sessions) →
`class_levels` → `classes` (needs levels) → `sections` (needs classes). Every `down()`
drops in reverse order so `migrate:rollback` is clean.

**Column-level changes to existing tables: none.** No `school_id`, no new user columns,
no index changes to `roles` or `permissions`. The permission gain is achieved by
inserting rows, not by altering a table.

### E.1 Existing files that will be modified

| File | Change | Why |
|---|---|---|
| `app/Support/ApiResponse.php` | **add** `paginated()` | §C1 — required before the first paginated endpoint. Additive; `success()` and `error()` untouched. |
| `database/seeders/PermissionSeeder.php` | **none** | Module 01's 7 permissions and their `sync()` remain authoritative for Module 01. |
| `database/seeders/DatabaseSeeder.php` | register the new seeders | ordered after `PermissionSeeder` |
| `tests/TestCase.php` | seed the Module 2 permission seeder too | authorization baseline for the new tests |
| `tests/Pest.php` | **add** Module 2 helpers | factories + `actingAsRole()`; no existing helper changes |
| `routes/api.php` | **add** the new groups | Module 01's 7 routes and their middleware are unchanged |

New seeders (all idempotent, all documented):

| File | Purpose |
|---|---|
| `database/seeders/SchoolSeeder.php` | the single placeholder school profile (`updateOrCreate` on `active_marker`) |
| `database/seeders/AcademicStructureSeeder.php` | class levels → classes (4 levels, 15 classes) |
| `database/seeders/AcademicPermissionSeeder.php` | the 24 permissions; uses **`syncWithoutDetaching()`** so Module 01 grants survive (§C5) |
| `database/seeders/AcademicCalendarSeeder.php` | one active session `2026/2027` + 3 terms, with the first term active |

Factories (following the existing `RoleFactory`/`StaffFactory` pattern):
`SchoolFactory`, `AcademicSessionFactory`, `TermFactory`, `ClassLevelFactory`,
`SchoolClassFactory`, `SectionFactory`.

---

## F. Out of scope — confirmed not planned

No model, migration, route, permission, controller, seeder row or test for:
`Student`, `Admission`, `Enrollment`, `Subject`, `Teacher`, `TeacherAssignment`,
`Assessment`, `Score`, `Result`, `Grading`, `Promotion`, `Attendance`, `Timetable`,
`Fee`, `Payment`, `Notification`, `Announcement`.

The academic structure is built so those modules attach to it later, but **no
relationship is declared to a model that does not exist** — `AcademicSession` has exactly
one `terms` relation, and no `hasMany(Student::class)` placeholder anywhere.

---

## G. Preservation statement

Unchanged and verified during the audit:

- All 7 Module 01 routes, their names and their middleware.
- `app/Support/ApiResponse::success()` / `::error()` signatures and output shape.
- `App\Providers\AuthServiceProvider` — the Gate, the super-admin bypass, the
  permission-ability resolution. **Module 02 adds no Gate code and no middleware.**
- `App\Models\User`, `Role`, `Permission`, `Staff` — extended with **zero** new
  relations to academic entities. A `User` is a person with a role; how that person
  relates to a class is an enrollment question, which is a later module.
- All 10 existing migrations and the `users`, `roles`, `permissions`,
  `permission_role`, `permission_user`, `staff` tables.
- `RoleSeeder`, `PermissionSeeder`, `SuperAdminSeeder` behaviour.
- The 87 existing tests.

**End of audit. Implementation begins in Stage 2.**
