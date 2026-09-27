# Module 03 — Staff Management: Audit

Stage 1 of 2. This audit is written against the repository as it stands after Module 02.
No code in this document has been implemented yet.

**Module:** 03 — Staff Management
**Depends on:** 01 (auth + authorization), 02 (school profile)
**Nature:** single-school, no `school_id`, no tenancy

---

## 0. Headline findings

Five things decide the shape of this module, and all five are properties of the **existing**
schema rather than choices I am making:

1. **`staff.user_id` is `NOT NULL` and `UNIQUE`.** A staff record cannot exist without a
   linked user account, and one user can hold at most one staff record. Module 03 cannot
   offer "create an employment record with no login" without altering a historical
   migration, so **staff creation creates the user account in the same transaction.**
2. **`staff` has no status column.** Employment status does not exist yet and must be added.
3. **`staff` has no employment date, phone, or designation.** All three are new columns;
   none of them duplicates anything on `users`.
4. **`staff.staff_number` already exists, is nullable and is unique.** No identifier scheme
   needs inventing, and the spec's "administrator may provide it" is already what the column
   supports.
5. **There is no staff API surface at all** — no routes, controller, resource, request,
   service, or staff permission. Module 03 adds all of it, but almost everything it touches
   is new; the only existing files it must *modify* are seven, listed in section 3.

---

## 1. Existing Staff / User architecture

### 1.1 `staff` table

Source: `database/migrations/2026_09_27_160005_create_staff_table.php` (Module 01, **must not
be modified**).

| Column | Type | Constraints | Module 03 verdict |
|---|---|---|---|
| `id` | bigint | PK | Reuse |
| `user_id` | bigint | **NOT NULL**, `UNIQUE`, FK→`users`, `cascadeOnDelete` | Reuse as-is. The unique index *is* the duplicate-linkage guard |
| `staff_type` | string | indexed | Reuse. Cast to `StaffType` |
| `staff_number` | string | **nullable**, `UNIQUE` | Reuse. Admin-supplied or derived (section 4) |
| `created_at` / `updated_at` | timestamp | | Reuse |

Absent: `status`, `employment_date`, `phone`, `designation`.

Two consequences of the constraints that are easy to get wrong:

- **`staff_number` is nullable and unique.** Under SQLite, MySQL and PostgreSQL, a unique
  index permits any number of `NULL`s, so leaving `staff_number` unset for several staff is
  legal. But *one* explicit value can never be reused.
- **`cascadeOnDelete` on `user_id` means deleting a User deletes the staff row.** This is
  load-bearing: `tests/Feature/Auth/StaffTypeTest > a staff record is removed when its user
  is deleted` asserts it. Module 03 must not change it to `restrictOnDelete` — and that
  settles the deletion question in section 5.

### 1.2 `users` table

Source: `0001_01_01_000000_create_users_table.php` plus
`2026_09_27_160004_add_authentication_fields_to_users_table.php`.

`id`, `name`, `email` (unique), `email_verified_at`, `password`, `remember_token`,
`role_id` (nullable FK, `restrictOnDelete`), `status` (`UserStatus`, indexed),
`last_login_at`, timestamps. Plus `password_reset_tokens` and `sessions`.

`users` carries **no** `staff_type`, `staff_number`, `phone`, or `status`-for-employment.
A Module 01 test asserts this deliberately:
`the users table carries no staff specific columns`.

### 1.3 Relationship

`User hasOne Staff` (`app/Models/User.php:84`), `Staff belongsTo User`
(`app/Models/Staff.php:51`). One-to-one, enforced by the unique index on `user_id`.

`User::staffType()` returns the `StaffType` **only when the user's role is `STAFF`**
(`app/Models/User.php:126`). So a `Staff` row attached to an `ADMIN` user exists in the
database but resolves to `null` through that helper. This matters: it means *creating a
staff record* and *making someone staff* are two distinct acts, and the role is what decides
which accessor reports a type.

### 1.4 Enums

| Enum | Values | Module 03 use |
|---|---|---|
| `App\Enums\StaffType` | `TEACHING`, `NON_TEACHING` | Reuse unchanged. No second type system |
| `App\Enums\Role` | `SUPER_ADMIN`, `ADMIN`, `REGISTRAR`, `STAFF`, `STUDENT` | Reuse. New staff are given `STAFF` |
| `App\Enums\UserStatus` | `ACTIVE`, `INACTIVE`, `SUSPENDED` | **User only.** Not reused for employment |
| `App\Enums\StaffType` → new `EmploymentStatus` | — | New, see section 2.1 |

There is deliberately **no** `TEACHING_STAFF` / `NON_TEACHING_STAFF` role, and a Module 01
test asserts their absence. Module 03 adds no role and does not change any role.

### 1.5 Existing authorization

- `permission:` middleware alias → `App\Http\Middleware\EnsureUserHasPermission`.
- `role:` → `EnsureUserHasRole`; `active:` → `EnsureAccountIsActive`; `verified:` →
  `EnsureEmailIsVerified`. All four aliases registered in `bootstrap/app.php`.
- Super Admin bypass lives in a single `Gate::before` callback, never in a controller.
- `User::hasPermission()` unions role permissions with direct per-user permissions, and
  returns true for a Super Admin. Two `STAFF` users can therefore hold different rights
  without a new role.

Module 03 reuses this exactly. **No new authorization architecture.**

### 1.6 Existing response and resource conventions

- `ApiResponse::success($data, $message, $status)`, `ApiResponse::paginated($collection)`,
  `ApiResponse::error()`, `ApiResponse::nullData()`.
- `ApiResponse::paginated()` keeps `data` as a flat list with `meta`/`links` as siblings.
  Module 03 reuses it, so the list shape is already correct by construction.
- Resources whitelist fields explicitly; `UserResource` is the model to follow, and it
  already withholds `password` and `remember_token` via the model's `$hidden` plus
  explicit whitelisting.
- Failure rendering lives in `bootstrap/app.php`: 401 / 403 / 404 / 422 / 429 / catch-all.
  `BusinessRuleViolation` → 422 with a message naming the actual obstacle.

### 1.7 Existing validation and list-filter conventions

- `AcademicListRequest` (abstract) — `per_page` capped 1-100, default 15; `search` max 120;
  a `booleanFlag()` helper for `1/0/true/false`; `filters()` normalises the values a service
  reads. Module 03's list request extends this.
- Normalise **before** the uniqueness check, in `prepareForValidation()`. Never in a model
  mutator, because a mutator that folds afterwards lets a value pass validation and then
  collide on the index, surfacing a client mistake as a 500.
- `App\Rules\PasswordRule::make()` is "the single definition of what an acceptable password
  looks like", and its own docblock says administrative user creation in a later module
  **must** use it.

### 1.8 Existing factories and seeders

- `StaffFactory` **exists** but has a latent bug: `definition()` returns `'user_id' => null`
  while the column is `NOT NULL`, so `Staff::factory()->create()` with no overrides fails
  with an integrity error. Every Module 01 test passes `user_id` explicitly, so it was never
  hit. Verified by running it.
- `UserFactory` already has `staff()`, `teachingStaff()`, `nonTeachingStaff()` states that
  create the linked `Staff` row in `afterCreating`.
- Seeders: `RoleSeeder`, `PermissionSeeder` (Module 01, uses **`sync()`**),
  `AcademicPermissionSeeder` (Module 02, uses `syncWithoutDetaching()`), `SchoolSeeder`,
  `AcademicStructureSeeder`, `AcademicCalendarSeeder`, `SuperAdminSeeder`.

### 1.9 Seeder ordering constraint (important)

`PermissionSeeder` uses **`sync()`**, which *replaces* a role's entire permission set. A
Module 03 permission seeder using `syncWithoutDetaching()` must therefore run **after**
`PermissionSeeder` in `DatabaseSeeder`, or a re-seed would silently revoke every Module 03
grant. This is exactly the reason Module 02's seeder is separate and additive. The ordering
already holds in both `DatabaseSeeder` and `tests/TestCase::setUp()`.

---

## 2. Gaps, and the decisions that close them

### 2.1 Employment status — **new enum, new column**

`staff` has no status. `UserStatus` must not be reused for it: `UserStatus::SUSPENDED` means
"revoke this person's tokens", which is an authentication action, and Module 01's
`EnsureAccountIsActive` gives it defined behaviour. Putting it on an employment record would
make "suspend the login" and "stop employing" the same field, and the spec forbids exactly
that.

**Decision (approved): new `App\Enums\EmploymentStatus` with three values, `ACTIVE`,
`INACTIVE` and `TERMINATED`, plus a `status` column on `staff` defaulting to `ACTIVE` and
indexed.**

`TERMINATED` earns its place because a school genuinely distinguishes "away" from "left",
and collapsing them makes a permanent leaver indistinguishable from a long absence. Two
`INACTIVE` staff records — one a two-week illness, one a resignation — are the same row
otherwise, and nothing can recover the difference.

`TERMINATED` is **terminal**. A staff record that has ended cannot be made active or
inactive again; `activate` and `deactivate` on it are 422s. Employment does not un-happen,
and a record that could be reactivated would let a leaver silently reappear on a current
staff list.

Status has exactly one implementation — `StaffService::setStatus()` — called by all three
routes, so there is no second code path for one meaning. The dedicated endpoints exist for
the two common transitions and are separately permission-gated; `PUT` accepts `status` for
the general case, matching Module 02's `CatalogStatus` pattern for records that are neither
singletons nor derived.

### 2.2 Staff number — **derived, not counted**

`staff_number` already exists, nullable and unique. The spec asks for something like
`STAFF-0001` without a fragile scheme and without `count + 1`.

**Decision:** `staff_number` is **optional** on create. When omitted, derive it from the
newly created user's primary key:

```
STAFF- + str_pad((string) $user->getKey(), 4, '0', STR_PAD_LEFT)
```

Unique by construction, because `user_id` is unique and each staff gets a distinct user.
No table scan, no max/count query, no lock, no race between two concurrent creates — the
two properties that make `count + 1` wrong are both avoided by never counting. An
administrator or registrar may still supply an explicit value (trimmed, upper-cased before
the uniqueness check, like `academic_sessions.name` and catalog `code`).

### 2.3 Account creation — **one transaction, role fixed in code**

`user_id` is `NOT NULL`, so there is no way to create a staff record without a user. Two
alternatives were considered and rejected:

- *Make `user_id` nullable in a new migration, so an employment record can exist without a
  login.* Rejected: it rewrites Module 01's core design decision, and every later module
  (subjects, attendance, results) would have to cope with staff who have no identity. The
  spec says do not modify historical migrations; altering the column they created in spirit
  is the same thing done more quietly.
- *Create the staff row first and the account in a second, separate call.* Impossible with a
  `NOT NULL` column, and the spec's "keep account creation separate" applies to the case
  where separation is possible.

**Decision:** `POST /staff` creates both in one transaction. `email` and `password` are
required inputs — so account creation is a **declared, documented part of the request**, not
a silent side effect. The password is validated by the existing `PasswordRule`, and the
response never echoes it.

**The role is set to `STAFF` in the service and is not an accepted request field.** There is
no `role` key in any Module 03 payload, so no request can ask for `SUPER_ADMIN`. This is
enforced by absence, which cannot be bypassed by mass assignment, rather than by a rule that
validates a value the client should not be able to send at all.

Consequence worth stating plainly: a user created here can be promoted later through
Module 01's `users.*` permissions, but Module 03 never grants a role.

### 2.4 Deactivation and the user account — **deliberately uncoupled**

**Decision:** `POST /staff/{id}/deactivate` sets `staff.status = INACTIVE` and touches
nothing else. It does **not** change `users.status`, revoke tokens, or delete anything.

The spec requires this explicitly, and the two statuses genuinely mean different things —
one is employment, one is a credential. The cost is honest and must be documented rather
than engineered away: **an `INACTIVE` staff member whose user is still `ACTIVE` can still
log in.** The two states are visible side by side in the resource so an administrator can
see the mismatch. Coupling them would mean a registrar's HR action silently cutting off
somebody's login, which is a security action disguised as a data edit.

### 2.5 Deletion — **prohibited**

**Decision: no `DELETE /staff/{id}`, and no `staff.delete` permission.**

Every alternative was considered:

- *Restrict delete to staff with no dependents.* Nothing depends on `staff` yet, so the
  guard would be untestable theatre that implies the endpoint is safe. The tables that will
  depend on it — subjects, assessments, scores, attendance, timetable — do not exist.
- *Let the FK decide.* The only FK is `user_id`, which cascades from *user* to *staff*, not
  the other way. There is nothing to refuse with.
- *Keep the endpoint for later.* The spec asks not to create files unless necessary.

Deactivation is the whole story. When those future tables arrive, their `restrictOnDelete`
FKs become the real guard, and by then the guard is testable. This is the conservative
choice the spec asks for, and it means the module ships **five** permissions rather than six.

### 2.6 Designation — **a nullable string, not a catalogue**

**Decision:** nullable `designation` string, free text, max 100. Values like `Principal`,
`Vice Principal`, `Teacher`, `Accountant`, `Secretary` are suggested in the docs and in
seeding, but nothing validates against a list.

No `departments`, `designations`, `organizational_units` table, and no enum of job titles.
A school is a single institution; a catalogue would need an admin UI, per-school titles, and
a migration every time a title is added, to solve a problem that a string does not have. If
titles ever need to be reported on consistently, that is a later module's decision.

### 2.7 Phone — **staff employment contact**

`users` has no phone column at all, so `staff.phone` is new information rather than a
duplicate. Nullable, max 30, trimmed. A staff work number legitimately differs from a
personal login identity, and a registrar needs it on a staff list.

### 2.8 Employment date

Nullable `date`, on or before today. A teacher employed in September and a principal
appointed in January both need this for reports that do not exist yet, and both can be
recorded accurately the day they are created. Rejecting a future date keeps a typo
(`2099-01-01`) out of the record.

### 2.9 What deliberately is **not** added

| Rejected | Why |
|---|---|
| `first_name` / `last_name` on `staff` | `users.name` is the identity. A second copy is a second source of truth that will disagree |
| `school_id` on anything | Single-school system |
| `is_active` boolean | Overlaps `status`, and boolean-plus-enum is the two-source problem the spec calls out |
| `employment_status` + `status` | One enum, one column |
| `employment_type` (full-time etc.) | No requirement, no consumer, no migration planned for it |
| Staff documents / photo | Upload is out of scope and the project has no storage layer |
| `salary`, `leave_balance` | Payroll and leave are later modules |
| Auto-generating a password | Nothing in this API can deliver a generated secret to a human; the administrator supplies one |

---

## 3. Existing files to reuse

| File | How Module 03 uses it |
|---|---|
| `app/Support/ApiResponse.php` | `success`, `paginated`, `error` — unchanged |
| `app/Models/Staff.php` | Extended: fillable, casts, `scope*`, a normalising mutator |
| `app/Models/User.php` | Reused for account creation. **Not modified** |
| `app/Enums/StaffType.php` | Reused unchanged |
| `app/Rules/PasswordRule.php` | Reused for the staff password |
| `app/Exceptions/BusinessRuleViolation.php` | Reused for state conflicts |
| `app/Http/Requests/Academic/AcademicListRequest.php` | **Moved** to `app/Http/Requests/ListRequest.php`; Module 02's five subclasses updated (4) |
| `app/Http/Controllers/Api/V1/Academic/AcademicSessionController.php` (pattern) | Reference for controller shape |
| `app/Http/Resources/Academic/*.php` (pattern) | Reference for resource shape |
| `app/Services/Academic/AcademicStructureService.php` (pattern) | Reference for service shape |
| `app/Http/Middleware/EnsureUserHasPermission.php` | Reused, unchanged |
| `config/school.php` | Reused for seeding staff names/emails |

## 4. Existing files that need modification

Only **seven**, and five of them are one-line additions.

| File | Change | Risk |
|---|---|---|
| `database/seeders/DatabaseSeeder.php` | Add `StaffPermissionSeeder` and `StaffSeeder` **after** `PermissionSeeder` | Ordering is load-bearing — see 1.9 |
| `tests/TestCase.php` | Seed the new permission seeder, after `PermissionSeeder` | Same |
| `database/factories/StaffFactory.php` | **Fix the `user_id => null` bug**; add `status` | None — the current factory cannot be used without overrides |
| `routes/api.php` | Add the staff route group | None |
| `docs/api/README.md` | Add Module 03 to the index | Docs only |
| `app/Models/Staff.php` | Fillable, casts, scopes, `staff_number` mutator | None — no behaviour relied on today changes |
| `app/Http/Requests/Academic/AcademicListRequest.php` | **Moved** to `app/Http/Requests/ListRequest.php` and Module 02's five subclasses updated | Pure move; Module 02's 15 filter tests must pass unchanged |

**On that last row:** `AcademicListRequest` lives under `Http/Requests/Academic/`, is
named for Module 02, and its `messages()` say "page size" and "search term" in academic
terms. Module 03 needs a base list request too. Three options were put to the reviewer:

- *(a)* Move it to a neutral namespace and update Module 02's five subclasses.
- *(b)* Have `StaffListRequest` extend it as-is, accepting academic-flavoured messages.
- *(c)* Give Module 03 its own list request, duplicating the rules.

**Decision (approved): (a).** The base moves to
`app/Http/Requests/ListRequest.php`, renamed to match its new, module-neutral scope, and
Module 02's five subclasses are updated to extend it. It is a pure move: the same rules,
the same messages, the same `MAX_PER_PAGE` of 100, and Module 02's own filter tests
(`ListFilterTest`, 15 tests) must pass unchanged afterwards, which is what proves it. A base
class named `Academic` governing staff paging is exactly the kind of wrong abstraction that
costs more later than a rename costs now.

---

## 5. New files genuinely required

Twelve, plus three test files. Each exists because Module 03 needs it, none for symmetry.

| File | Why it must be new |
|---|---|
| `app/Enums/EmploymentStatus.php` | No existing enum means employment status (2.1) |
| `app/Http/Requests/ListRequest.php` | **Moved from `AcademicListRequest`.** Staff needs the same list-filter validation Module 02 built, and a class named `AcademicListRequest` cannot be the shared base of a non-academic module (D4) |
| `app/Http/Requests/Staff/Concerns/ValidatesStaffFilters.php` | Filter rules shared by the list request and its tests |
| `app/Http/Requests/Staff/Concerns/ValidatesStaffRecord.php` | Number/email normalisation is needed by both the store and update requests, and duplicating it is how the two drift apart |
| `app/Http/Resources/StaffResource.php` | No staff representation exists at all |
| `app/Services/Staff/StaffService.php` | Account creation + staff write + status transitions is real logic, not a controller's job |
| `app/Http/Controllers/Api/V1/Staff/StaffController.php` | No staff controller exists |
| `app/Http/Requests/Staff/StoreStaffRequest.php` | Create payload, incl. account fields |
| `app/Http/Requests/Staff/UpdateStaffRequest.php` | Amend payload |
| `app/Http/Requests/Staff/StaffListRequest.php` | Query-string validation (list filters were the source of four defects in Module 02) |
| `database/migrations/2026_09_27_171000_add_employment_fields_to_staff_table.php` | `status`, `employment_date`, `phone`, `designation` (2.1, 2.6–2.8) |
| `database/seeders/StaffPermissionSeeder.php` | 5 permissions, additive, `syncWithoutDetaching()` |
| `database/seeders/StaffSeeder.php` | 2 development staff records (1 teaching, 1 non-teaching), local/testing only |
| `tests/Feature/Staff/*.php` | 3 files, 53 tests |

**Deliberately not created:** `StaffRepository`, `StaffManager`, `StaffFactoryService`,
`StaffHelper`, `StaffTransformer`, `StaffQueryBuilder`, `StaffPermissionService`,
`StaffObserver`, `CreateStaffAccountRequest`, `StaffSearchRequest`, a `departments` table, a
`designations` table, events, listeners, DTOs, interfaces, or a base controller.

**`StaffService` may be replaced by controller logic** if the total real logic is small
enough. Creating a user inside a transaction plus two status flips is enough to justify one
service; if it turns out not to be, the controller should absorb it rather than the service
existing for its own sake.

---

## 6. Database changes

One **additive** migration. No historical migration is edited, and no existing data is
dropped or altered.

```php
Schema::table('staff', function (Blueprint $table) {
    $table->string('status')->default(EmploymentStatus::ACTIVE->value)->after('staff_type')->index();
    $table->date('employment_date')->nullable()->after('staff_number');
    $table->string('phone', 30)->nullable()->after('employment_date');
    $table->string('designation', 100)->nullable()->after('phone');
});
```

| Column | Why it is needed | Why this type |
|---|---|---|
| `status` | Employment status does not exist at all | String + index, matching `UserStatus`/`CatalogStatus`. Default `ACTIVE` so a row created outside the API is still valid |
| `employment_date` | "Employment/profile information" in the spec scope | `date`, nullable — a record created before the date is set is legitimate |
| `phone` | Employment contact; `users` has no phone | `string(30)`, matching `School.phone` |
| `designation` | Job title | `string(100)`, free text, nullable. Not a catalogue (2.6) |

Indexes: `status` indexed because the list filters on it. `staff_type` is already indexed.
`user_id` and `staff_number` are already unique. No index is added for a column nothing
filters by.

`down()` drops exactly these four columns. The migration is additive and reversible, and
applies cleanly to a populated `staff` table because every new column is nullable or has a
default.

---

## 7. API endpoints

All under `/api/v1`, all on `['auth:api', 'active']` plus a `permission:` gate, matching
Module 02 exactly.

| Method | Path | Permission | Returns |
|---|---|---|---|
| `GET` | `/staff` | `staff.view` | Paginated list |
| `POST` | `/staff` | `staff.create` | 201 |
| `GET` | `/staff/{staff}` | `staff.view` | 200 |
| `PUT` | `/staff/{staff}` | `staff.update` | 200 |
| `POST` | `/staff/{staff}/activate` | `staff.activate` | 200 |
| `POST` | `/staff/{staff}/deactivate` | `staff.deactivate` | 200 |

**Six routes. No `DELETE`** (2.5).

`PUT` only, not `PATCH`: the brief says do not introduce PATCH unless the existing
architecture already has it, and the review is correct that Module 02's academic routes use
`Route::match(['put','patch'])`. For Module 03 I will register **`Route::put` alone** and
return **405** for `PATCH`. Rationale: staff amend is a whole-record write, and a single
verb is one fewer thing for a client to discover and mis-try. If a client sends `PATCH` the
405 names the supported methods. *Flagging this as a deliberate divergence from Module 02's
pattern; it is a one-line change if consistency is preferred.*

`activate` / `deactivate` are `POST`, not `PATCH`, for Module 02's reason: a state
transition with an effect beyond the record, separated so it can be granted independently of
the ability to edit a staff record.

### 7.1 Query parameters

| Parameter | Rule |
|---|---|
| `search` | Optional, string, max 120. Case-insensitive match on staff number, **and** on the linked user's name and email |
| `staff_type` | Optional, must be `TEACHING` or `NON_TEACHING` |
| `status` | Optional, must be `ACTIVE`, `INACTIVE` or `TERMINATED` |
| `account_status` | Optional, one of the `UserStatus` values |
| `has_account` | Optional boolean, `1/0/true/false` |
| `per_page` | Optional integer, 1-100, default 15 |

All validated. **No `sort`, no `direction`** — the same deliberate decision Module 02
recorded in audit D.11. Fixed order: `employment_date` descending, nulls last, then `name`
ascending, so the list reads as most-recently-appointed first and a stable tiebreak keeps
pagination deterministic. The `staff_type`/`status` filters are rejected as 422s with the
permitted values, not silently matched to nothing — that was Module 02 defect 4.4.

`has_account` is included because the brief lists it, and it is honestly implemented as an
existence check on the linked user rather than faked. Because `staff.user_id` is `NOT NULL`
and `unique` (1.1), **every** staff record has an account, so `has_account=true` returns
everything and `has_account=false` returns nothing. That is not a bug to paper over with a
second `status`-like column; the test asserting an empty result for `false` is what pins the
schema constraint down in executable form. If the `users` table ever stops carrying `staff`,
this filter starts returning a real subset for free.

---

## 8. Permissions

Five. `staff.delete` is deliberately **not** created, because there is no delete endpoint
(2.5) — a permission for an operation that does not exist is a grant with no meaning.

| Permission | Purpose |
|---|---|
| `staff.view` | List and read staff |
| `staff.create` | Create a staff record and its account |
| `staff.update` | Amend a staff record |
| `staff.activate` | Return a staff member to active employment |
| `staff.deactivate` | Record that a staff member is no longer active |

### 8.1 Role grants

| Role | Grants | Reasoning |
|---|---|---|
| `SUPER_ADMIN` | All 5 | Existing bypass; listed explicitly for consistency with Module 02 |
| `ADMIN` | All 5 | Runs the school day to day; hiring and departure are their business |
| `REGISTRAR` | `view`, `create`, `update` | Builds the staff establishment while admitting and placing people. **No `activate`/`deactivate`** — those are employment decisions with a security dimension, and the brief says not to grant account/security administration to a registrar without justification |
| `STAFF` | none | A staff member manages nobody. The brief says grant only if the existing API needs it; it does not, and the self-service profile is Module 01's `profile.*` |
| `STUDENT` | none | No staff-management access |

`REGISTRAR` not getting `staff.deactivate` is a judgement call worth stating: a registrar
will legitimately need to record that a teacher has left, and refusing them that creates
pressure to grant it "temporarily". But deactivating a record whose user is still `ACTIVE`
(2.4) is how you accidentally cut off a login, so it is held at `ADMIN`. If the school
disagrees, it is a one-line change in the seeder.

Seeded with `updateOrCreate()` on name and granted with `syncWithoutDetaching()` — the
Module 02 pattern, so a re-seed cannot revoke Module 01's `sync()` grants.

---

## 9. Tests required

Three files. Behaviour, not implementation. **As built: 53 tests across the three files**
(23 + 15 + 14), plus one addition to `StaffTypeTest`.

**`tests/Feature/Staff/StaffManagementTest.php` — CRUD, ~20**

- list requires authentication (401); student is refused (403)
- list returns a flat `data` with sibling `meta`/`links`; `data.data` absent
- create makes a staff row **and** a linked user with the `STAFF` role
- create never accepts a `role` key; `role: SUPER_ADMIN` in the body is ignored, and the
  created user is `STAFF`
- create rejects a weak password via the shared `PasswordRule`
- create rejects a duplicate email
- create normalises a supplied `staff_number` (trim, upper case) before the uniqueness check
- create derives `STAFF-0007` style when no number is given, and derives it uniquely
- show returns the record; a missing id is 404 `Resource not found.`
- update amends and ignores the current row for uniqueness
- update rejects a `staff_number` already held by a *different* staff
- update cannot repoint `user_id` at another user (duplicate linkage is 422, not 500)
- every field is optional-free per project convention: full-record write, `name`/`staff_type`
  required — so an amend needs the identity fields. **`email` is not required and not
  accepted on an amend** — see §13, deviation D1

**`tests/Feature/Staff/StaffStatusTest.php` — transitions, ~8**

- activate sets `ACTIVE`; reactivating an already-active member is a 200 no-op
- deactivate sets `INACTIVE`; deactivating an inactive member is a 200 no-op
- deactivation leaves `users.status`, `email_verified_at` and the user's tokens untouched —
  asserted explicitly, because the coupling is the thing being refused
- deactivating a staff record whose user is `SUSPENDED` still works
- each transition is separately permission-gated

**`tests/Feature/Staff/StaffSecurityAndFilterTest.php` — exposure, filters, matrix, ~12**

- the staff resource never contains `password`, `remember_token`, `password_reset_tokens`,
  `tokens`, or any token hash — asserted by walking the serialised payload recursively, not
  by a list of expected keys, so a future field cannot slip through
- filters: `search` by staff number / name / email, `staff_type`, `status`,
  `account_status`, `has_account=true|false`, `per_page` cap and default
- each invalid filter is 422 naming the permitted values
- authorization matrix across all five roles for view / create / update / activate /
  deactivate
- `staff_number` unique index still refuses a duplicate at the database level

Plus two additions to existing files:

- `tests/Feature/Auth/StaffTypeTest.php` — one test that the `STAFF` role is what
  `staffType()` keys on, now that staff records are created through the API
- `database/factories/StaffFactory.php` — the `user_id` bug fix gets its own test, because
  a factory that cannot be used without overrides will be discovered again

---

## 10. Risks and edge cases

| # | Risk | Handling |
|---|---|---|
| 1 | `staff_number` unique permits many `NULL`s but only one of any value | Documented; an explicit value is never reused |
| 2 | Two concurrent creates with the same supplied `staff_number` | The unique index refuses the second; it surfaces as a 422 from a `Rule::unique` pre-check in the common case and as an integrity error in the true race. Mitigated by the derived default, which cannot collide |
| 3 | `cascadeOnDelete` on `user_id` means deleting a user silently deletes a staff record | Not changed — Module 01 asserts it. Documented as a residual risk: staff history is only as durable as its user |
| 4 | An `INACTIVE` staff member can still log in (2.4) | Intended and uncoupled. Both statuses visible in the resource; documented as the first thing a reader of the docs should know |
| 5 | Creating staff also creates a login account | Made explicit: `email` + `password` are required inputs, documented in the endpoint reference, and the response shows the account |
| 6 | A client might expect `role` to be settable | The field is absent entirely; a body containing it is ignored. Tested |
| 7 | `employment_date` in the future | Rejected by validation |
| 8 | `designation` free text will drift in spelling (`Teacher` vs `teacher`) | Accepted deliberately (2.6). Normalised only by trimming. A title that must be reported on consistently needs a catalogue, which is a later decision |
| 9 | `search` joins `users`, so listing is not a single-table read | Indexed lookups on a small table; acceptable at school scale. `withQueryString` preserved so paging links keep the filter |
| 10 | Module 02's `AcademicListRequest` is Module-02-named (section 4) | **Approved:** moved to `Http/Requests/ListRequest` as a pure move, proven by Module 02's 15 filter tests passing unchanged |
| 11 | `status` is settable by both `PUT` and the dedicated endpoints | One implementation (`StaffService::setStatus`) serves all three routes, so there is one code path, not two. `TERMINATED` is terminal and enforced |

---

## 11. What must remain untouched

- **Every historical migration**, Module 01's and Module 02's. The single new migration is
  additive.
- **`users` table structure.** No staff column is added to it, and no staff-specific column
  is removed. A Module 01 test asserts the exact column list.
- **`Role` values.** No `TEACHING_STAFF`, no `NON_TEACHING_STAFF`, no sixth role.
- **`StaffType` values.** `TEACHING` and `NON_TEACHING` only.
- **`UserStatus` semantics**, including `SUSPENDED` revoking tokens.
- **`PermissionSeeder`'s `sync()`**, and the seeder ordering that protects it.
- **`staff.user_id` nullability and cascade behaviour.**
- **Modules 01 and 02 API responses, routes, and tests.**
- **No `school_id`, `tenant_id`, or branch identifiers anywhere.**

---

## 12. Approval checklist

All ten decisions were put to the reviewer and approved.

| # | Item | Decision | Section |
|---|---|---|---|
| 1 | Account creation inside `POST /staff`, role fixed to `STAFF` | approved | 2.3 |
| 2 | `EmploymentStatus` = `ACTIVE` / `INACTIVE` / `TERMINATED`, terminal | approved | 2.1 |
| 3 | `staff_number` optional; derived `STAFF-####` when omitted | approved | 2.2 |
| 4 | No `DELETE` endpoint, no `staff.delete` permission | approved | 2.5 |
| 5 | `deactivate` does not touch the user account or tokens | approved | 2.4 |
| 6 | `designation` a nullable string; no catalogue | approved | 2.6 |
| 7 | No name/email/password columns on `staff` | approved | 2.9 |
| 8 | `PUT` only; `PATCH` returns 405 | approved | 7 |
| 9 | 5 permissions; registrar cannot activate/deactivate | approved | 8 |
| 10 | List-filter base moved to a neutral namespace | approved | 4 |

Implementation may proceed.

---

## 13. Implementation record

Implemented and verified. Full suite **329 passed, 1456 assertions** (275 before Module 03, so
+54, no regressions in Modules 01–02). `migrate:fresh --seed` clean. Verified against a live
`php artisan serve` on top of the seeded database, not only in-process.

### 13.1 Deviations from this audit

| # | Audit said | Built | Why |
|---|---|---|---|
| **D1** | Amend requires `name` and `email`; email is a full-record field | Amend requires `name` and `staff_type`. **`email` and `password` are not accepted and silently ignored** | A registrar holds `staff.update` but not `users.update`. If this endpoint accepted credentials, a registrar could repoint any account's address and then take it over through Module 01's password reset — privilege escalation via a field that only looked like a profile field. Credentials now change only through Module 01, where they are already permission-gated. `name` stays amendable: it is a display name, not a credential |
| **D2** | 405 "names the supported methods" in the body | Also sends a correct `Allow: GET, HEAD, PUT` header | Laravel drops the exception's headers when the API error renderer rebuilds the response, so the 405 arrived headerless. `bootstrap/app.php` now merges the original headers back. RFC 9110 requires `Allow` on a 405; the body text is not a substitute |
| **D3** | `EmploymentStatus` = 3 values, `TERMINATED` terminal | Unchanged, but the audit's own §2.1 originally said two values and its test notes assumed `PUT` was the only route to status | Corrected while writing: the two-value version could not record a permanent departure at all, which is the fact an establishment record exists to hold |
| **D4** | "Move the list-filter base" | `AcademicListRequest` → `app/Http/Requests/ListRequest.php`, five subclasses updated, old file deleted | As approved. The old name would have had a non-academic module extending a class called `AcademicListRequest` |
| **D5** | "Registrar cannot activate/deactivate, because deactivating a record whose account is still active is how somebody's login gets cut off" | Grant matrix is as approved, but **that rationale is wrong and was corrected** | Deactivation explicitly does *not* touch the login, so it is not a security action by construction. The real reason to hold the transitions at `ADMIN` is that ending an employment is a supervisory decision, not a data edit. The matrix stands; the argument for it did not |

### 13.2 What was verified live, not just in tests

- `PATCH /staff/{id}` and `DELETE /staff/{id}` → `405` with `Allow: GET, HEAD, PUT`; the header
  names neither `PATCH` nor `DELETE`.
- `POST /staff` with `role: SUPER_ADMIN` in the body → `201`, and the created account is
  `STAFF` in the database. Not merely absent from the response — checked in `users.role_id`.
- `PUT /staff/{id}` with `email` and `password` in the body → `200`, and the account's email is
  unchanged afterwards.
- `deactivate` → `status: INACTIVE` with `account_status` still `ACTIVE`, i.e. the two statuses
  really are independent on the wire.
- `TERMINATED` via `PUT`, then `activate`, `deactivate` and `amend` all `422`, while `GET`
  still returns `200`.
- `has_account=false` → `200` with zero rows.
- A `STAFF`-role token gets `403` on both `GET /staff` and `POST /staff`.
- `StaffSeeder` is now gated on `local`/`testing` like `SuperAdminSeeder`, so `migrate:fresh
  --seed` on a production environment creates no login accounts. The audit described the
  seeder as development-only but the file had no guard.

### 13.3 Known and accepted

- `has_account` cannot currently be selective. `staff.user_id` is `NOT NULL` and `unique`, so
  every record has an account; `true` returns everything and `false` nothing. Pinned by a test
  so the constraint is remembered.
- `PUT` is whole-record only, so a client cannot partially update. Intended (see the endpoint
  reference, §1.3).
- `employment_date` may be left null, so ordering puts undated records last.
- Module 03 adds no `email` change path of its own. Until Module 01 exposes a user-administration
  endpoint for that, an administrator changes a staff member's address through the direct
  user endpoints only. Flagged for the Module 04+ briefing.

