# Module 03 - Staff Management: Final Report

Companion to [module-03-staff-management-audit.md](module-03-staff-management-audit.md) (the
design audit that preceded implementation) and [../api/staff-management.md](../api/staff-management.md)
(the endpoint reference).

---

## 0. Status

**Complete and verified.**

| Check | Result |
|---|---|
| Test suite | **329 passed**, 1456 assertions, 0 failures |
| Before this module | 275 passed, 1076 assertions — so +54 tests, no regressions |
| Pint | clean |
| `migrate:fresh --seed` | succeeds, all 20 migrations + 8 seeders |
| Route table | 43 routes (37 previous + **6** Module 03) |
| Live HTTP smoke | performed against `artisan serve` on the seeded database |
| Module 01/02 regression | none; all 275 pre-existing tests pass unchanged |
| Deviations from the audit | 5, all recorded in audit §13.1. One is a security narrowing (D1) and two correct the audit's own reasoning (D3, D5) |

---

## 1. What was built

### 1.1 Stack decisions

| Decision | Why |
|---|---|
| `staff.user_id` stays `NOT NULL` + `unique` | It is Module 01's column and it makes "one login, at most one staff record" a database fact. A nullable FK with a separate `has_account` flag would make an unaccounted-for employee representable, and nothing needs that. |
| Create takes `email` + `password` as **required input** | The schema forces this endpoint to create an account. Requiring the credentials makes the consequence explicit instead of generating a password nobody was told. |
| Role fixed to `STAFF` in code, no `role` key in the request | A payload cannot request `SUPER_ADMIN`. The absence of the key is the enforcement. |
| Account created already verified | An administrator is provisioning a colleague's login, exactly as the Super Admin seeder does. A verification link nobody is watching would only produce locked-out staff. |
| `EmploymentStatus` = 3 values, `TERMINATED` terminal | Two values cannot record a permanent departure, which is the fact an establishment record exists to hold. |
| Staff number **derived from the account's primary key** | `count() + 1` races: two concurrent creates read the same count and the loser's insert dies as a 500. A primary key cannot be read twice. |
| One service, not a controller | Creating a user inside a transaction, plus a whole-record write, plus a terminal-state rule, is real logic. |
| `status` accepted on `PUT` **and** as two dedicated endpoints | The dedicated endpoints are the two routine transitions and are separately permission-gated; `PUT` is the general case and is the only route to `TERMINATED`. All three go through one `setStatus`, so "what a transition means" has one implementation. |
| No `DELETE` | Employment ends through `status`; the record is kept. See endpoint reference §1.1. |
| `designation` free text | A catalogue needs an admin UI and a migration per new title, to solve a problem a 100-character string does not have. |
| Permissions, not roles, on routes | Inherited from Modules 01/02. A single staff member can hold `staff.view` without a new role. |
| One shared list-filter base, neutrally named | `AcademicListRequest` could not be the base of a non-academic module. Moved, five subclasses updated, its own 15 tests unchanged. |

### 1.2 Deliverables

- 1 migration (additive), 1 enum, 1 model extended, 1 factory fixed
- 1 permission seeder, 1 development seeder
- 1 service, 4 form requests + 2 shared validation concerns, 1 resource, 1 controller
- 6 routes
- 3 test files, 53 tests, plus 1 addition to `StaffTypeTest`
- 3 documents (this report, the audit, the endpoint reference) + the API index

---

## 2. How the audit's findings were closed

All ten approved decisions are implemented as specified. Five deviations are recorded, two of
which correct the audit rather than override it:

| Audit item | Delivered | Note |
|---|---|---|
| 2.3 Account creation, role fixed in code | as specified | Role fixed in the service; the request has no `role` key at all. |
| 2.1 `EmploymentStatus`, `TERMINATED` terminal | **corrected** | The audit's §2.1 said *two* values; its own test notes then needed three. Built as three. Audit §13.1 D3. |
| 2.2 Staff number derived, not counted | as specified | Derived from the new account's id, `STAFF-0007` style. |
| 2.5 No delete | as specified | No route, no permission. `DELETE` → 405 with `Allow: GET, HEAD, PUT`. |
| 2.4 Deactivation uncoupled from the account | as specified | Asserted on the live wire, not just in the model. |
| 2.6 `designation` a nullable string | as specified | |
| 2.9 No identity columns on `staff` | as specified | `name`/`email` read through the relationship. |
| 7 `PUT` only, `PATCH` → 405 | **widened** | 405 now carries a correct `Allow` header. Laravel's API renderer was dropping the exception's headers, so the response was RFC-noncompliant. Audit §13.1 D2. |
| 8 Registrar cannot activate/deactivate | **rationale corrected** | The matrix is unchanged; the *argument* was wrong (it claimed deactivation cuts off logins, which it explicitly does not). Audit §13.1 D5. |
| 4 List-filter base moved | as specified | `AcademicListRequest` → `ListRequest`. |
| §9 test "amend requires `name`/`email`" | **narrowed** | Amend requires `name`/`staff_type` and does not accept `email` or `password` at all. Audit §13.1 D1 — this is the one substantive security change. |

### 2.1 The one real security change (D1)

The audit specified that an amend be a whole-record write requiring `name` and `email`. Built
differently, on purpose:

**`PUT /staff/{id}` does not accept `email`, `password`, `role` or `user_id`.**

`REGISTRAR` holds `staff.update` but deliberately does not hold `users.update`. Had this
endpoint accepted credentials, a registrar could repoint any account's email address and then
take that account over through Module 01's password-reset flow — a registrar turning themselves
into a super admin in two requests, through a field that only looked like a profile field. The
audit's own §2.9 principle ("credentials stay with Module 01") is what decided it.

Sending those keys is **ignored, not rejected**, so a client that sends a full record does not
break. `name` remains amendable: a display name is not a credential. Credentials change through
Module 01.

Verified live: `PUT` with `email` and `password` in the body returned `200` and the account's
email was unchanged afterwards.

---

## 3. Design decisions worth recording

### 3.1 Two statuses, and the gap between them is intentional

Every staff response carries `status` (employment: `ACTIVE`/`INACTIVE`/`TERMINATED`) **and**
`account_status` (login: `ACTIVE`/`INACTIVE`/`SUSPENDED`). Deactivating a staff member changes
only the first. Cutting off a login is Module 01's `users.*` business.

Both appear side by side so a record reading `status: INACTIVE, account_status: ACTIVE` — a
former member who can still log in — is **visible** rather than something a client has to go
and discover by fetching a second resource. Asserted at the database, the model and the wire.

### 3.2 `has_account` is honest rather than useful

`staff.user_id` is `NOT NULL` and `unique`, so every staff record has an account by
construction. `has_account=true` returns everything; `has_account=false` returns nothing.

It is implemented as a real left-join existence check, not hardcoded, and the behaviour is
pinned by a test so the constraint stays visible. The alternative — dropping the filter until
it can be selective — would have quietly removed a filter the brief asked for.

### 3.3 `PUT` is a whole-record write, and says so in the validation error

`name` and `staff_type` are required on amend. There is no partial form. A client cannot
half-update a record by omitting a field, and a client that tries gets a 422 naming the fields
it must send back.

### 3.4 Normalisation happens before the uniqueness check

`staff_number` and `email` are trimmed, and upper-cased (number) or lower-cased (email), in
`prepareForValidation` — *before* `Rule::unique` runs. So `"  sta-07  "` and `"STA-07"` are one
number, and the second is a 422 rather than two rows that differ by whitespace. Model mutators
repeat the normalisation as defence in depth for non-HTTP writers.

### 3.5 Repeated transitions are no-ops, not conflicts

Activating an active member, or deactivating an inactive one, is a `200` with the state
unchanged. A double-clicked button should not be told its request was dangerous. This is
Module 02's rule, applied consistently.

### 3.6 Filters are validated, not ignored

An unknown `status` or `staff_type` is a `422` naming the permitted values, never a silently
empty page. This was Module 02 defect 4.4; the shared base exists so it cannot recur.

### 3.7 `designation` is not a catalogue

Any string up to 100 characters. Documented, seeded, and free. When a school needs a
constrained list it gets a `designations` table and an admin UI — a real requirement, not
something to pre-build for.

---

## 4. Defects found during verification and fixed

| # | Defect | Fix |
|---|---|---|
| 4.1 | **The 405 response had no `Allow` header.** Laravel's API exception renderer rebuilds the response and discards the headers Symfony attached, so `PATCH`/`DELETE` answered `405` with a body sentence and nothing else. RFC 9110 requires `Allow` on a 405. | `bootstrap/app.php` renders `MethodNotAllowedHttpException` and merges the original headers back. Verified over the wire: `Allow: GET, HEAD, PUT`. |
| 4.2 | **`StaffSeeder` had no environment guard.** Its own docblock said "development convenience only", but it was registered in `DatabaseSeeder` unconditionally — so `migrate:fresh --seed` against a production database created two login accounts with a hardcoded default password. | Added the same `app()->environment(['local', 'testing'])` guard `SuperAdminSeeder` uses, with the same warning message. |
| 4.3 | **`StaffFactory` could not create a staff row.** It did not create or link a user, so `user_id` was `null` against a `NOT NULL` column; every use needed a manual override. | Factory now builds a `STAFF` user and links it. A factory that cannot be used without overrides gets discovered again. |
| 4.4 | **`User::factory()->create()` silently produces an `ADMIN`.** `RoleFactory` defaults to `ADMIN`, and `roles.name` is unique with roles already seeded, so the second bare factory call in a test throws on the unique index. Pre-existing, but Module 03 multiplies the exposure. | `StaffFactory` uses `User::factory()->withRole(Role::STAFF)`. All Module 03 tests use the `userWithRole()` helper. The `RoleFactory` default itself is left alone — changing it would be a Module 01 behaviour change, and it is recorded here instead. |
| 4.5 | **The registrar rationale was wrong.** The audit, the seeder comment and the endpoint reference all justified withholding `staff.activate`/`staff.deactivate` by claiming deactivation is how "somebody's login gets cut off". Deactivation explicitly does not touch the account, so the argument contradicted the code it defended. | All three corrected. The matrix is unchanged — ending an employment is a supervisory decision, not a data edit. The *reason* was the weak part. Audit §13.1 D5. |
| 4.6 | **Test-side rate limiting.** The five-role authorization matrix issued 6 logins against `throttle:login`, so the matrix failed with a `429` that had nothing to do with authorization. | Tokens are reused across the matrix instead of re-authenticating per role. |
| 4.7 | A multi-line `php artisan tinker --execute` is mis-parsed by PowerShell (it strips `$` and mangles quotes). | Not a code defect. Used a temporary bootstrap script for the database-level checks. Same as Module 02 report §4.11. |
| 4.8 | **`PUT /staff/{id}` was not transactional.** An amend writes two tables — the display name on the account, everything else on the staff row — and the user was saved first with no rollback. A failure on the staff save would have left somebody's name changed while the caller was told the amend failed: a changed record and a rejection for one request. Found by reading the service against its own create path, which *was* transactional. | `update()` wrapped in `DB::transaction`, for the same reason `create()` is. Verified by mutation: with the transaction removed the new rollback test fails, so it is not a vacuous test. |

---

## 5. Test coverage

| File | Tests | Covers |
|---|---|---|
| `tests/Feature/Staff/StaffManagementTest.php` | 24 | list authn/authz, envelope shape, atomic create, role fixed in code, password policy, duplicate email, number normalisation and derivation, show/404, whole-record amend, transactional rollback, uniqueness ignoring own row, `user_id` not repointable |
| `tests/Feature/Staff/StaffStatusTest.php` | 15 | activate/deactivate, idempotence, account/verification/tokens untouched, suspended account, `TERMINATED` via `PUT`, all transitions refused after termination, amend refused, no `status` on create, per-transition permission gating, students and staff refused, inactive account refused |
| `tests/Feature/Staff/StaffSecurityAndFilterTest.php` | 14 | recursive credential/token exposure walk, exact field set, search by number/name/email, all five filters, `has_account` honesty, boolean spellings, `per_page` cap and default, invalid value names the permitted values, fixed ordering, filters in paging links, five-role matrix, per-user grant, unique index at the database level |
| `tests/Feature/Auth/StaffTypeTest.php` | +1 | a staff member created through the API carries the single `STAFF` role, is still a `staff_type` classification, and both types still authenticate through the one login endpoint |

**53 new tests. 329 total, all passing.**

The exposure test walks the serialised payload recursively looking for anything
credential-shaped, rather than asserting a list of expected keys — so a column added to
`staff` or `users` later cannot leak into a response without a test failing.

The rollback test makes the staff write fail on purpose and asserts the account rename did not
survive it. A uniqueness race is the case that matters in production and cannot be staged
through the API (validation catches it first), so the test asserts the general property
instead. It was confirmed by mutation: removing the transaction makes it fail.

---

## 6. Live verification performed

Against `php artisan serve` on the freshly seeded database, not only in-process. The full
transcript is in audit §13.2; the results that matter:

| Scenario | Result |
|---|---|
| `GET /staff` unauthenticated | `401` |
| Login as `SUPER_ADMIN`, `GET /staff` | `200`, `data`/`meta`/`links` siblings, 12 documented fields per row |
| `GET /staff?search=amina` / `?staff_type=NON_TEACHING` / `?status=…&account_status=…` | `200`, filtered |
| `GET /staff?has_account=false` | `200`, zero rows |
| `GET /staff?status=WRONG` | `422` naming the permitted values |
| `GET /staff/9999` | `404 Resource not found.` |
| `POST /staff` | `201`, number derived `STAFF-0004`, email lower-cased, `account_status: ACTIVE` |
| `POST /staff` duplicate email / weak password / future employment date | `422` each |
| `POST /staff` with `role: SUPER_ADMIN` | `201` — and `users.role_id` verified as **`STAFF`** in the database |
| New account logs in | `200`, `role: STAFF`, `staff_type: TEACHING`, `status: ACTIVE` |
| `PUT /staff/{id}` full record | `200`, amended |
| `PUT` missing `staff_type` | `422` |
| `PATCH /staff/{id}` | `405`, `Allow: GET, HEAD, PUT` |
| `DELETE /staff/{id}` | `405`, `Allow: GET, HEAD, PUT` |
| `PUT` with `email` + `password` | `200`, email **unchanged** |
| `deactivate` | `status: INACTIVE`, `account_status: ACTIVE` — the two really are independent |
| `deactivate` twice | `200` no-op |
| `activate` | `ACTIVE` |
| `PUT status: TERMINATED` | `TERMINATED` |
| `activate` / `deactivate` / `amend` after termination | `422` each |
| `GET` a terminated record | `200` — still readable |
| `STAFF`-role token on `GET /staff` and `POST /staff` | `403` both |

Seeded data confirmed in the database: two staff records, one per `staff_type`, numbers
`STAFF-0001`/`STAFF-0002`; the five `staff.*` permissions present; the grant matrix exactly as
documented.

---

## 7. Residual risks and future work

| # | Item | Status |
|---|---|---|
| 1 | `has_account` cannot be selective | Accepted. `user_id` is `NOT NULL`. Pinned by a test. |
| 2 | No email-change path for a staff member | By design (D1). Module 01 owns credentials, but it exposes no user-administration *endpoint* for changing another user's address — only `profile.*` for one's own. Worth deciding in the Module 04+ briefing; a registrar cannot currently correct a mistyped staff email through the API. |
| 3 | `UserFactory` bare `create()` yields `ADMIN` | Pre-existing Module 01 quirk, documented rather than changed. Worth fixing in its own module. |
| 4 | `employment_date` nullable | Permitted, so ordering puts undated records last. A `created_at` fallback would be arbitrary. |
| 5 | `phone` on `staff` duplicates the concept of a user contact number | `phone` here is the *employment* contact. Module 01's user does not carry a phone today. Decide in the Module 04+ briefing whether there should be one. |
| 6 | No `staff_number` bulk re-sequencing | Numbers come from account ids, so they are never sparse in a fresh install and re-sequencing a live one would break references printed on documents. Intentionally absent. |

Two of the original seven items are now closed rather than open: the `update` atomicity gap
(4.8) and the `StaffSeeder` production guard (4.2). Item 5 — the `phone` duplication — is the
one worth a decision before the next module adds a second phone number somewhere.

---

## 8. Preservation statement (verified)

| Must remain untouched | Result |
|---|---|
| `2026_09_27_160005_create_staff_table.php` (historical migration) | **unmodified** — `git status` shows no change. Module 03 adds a separate additive migration. |
| Module 01 and 02 tests | all 275 pass unchanged |
| `users` table columns | unchanged; no Module 03 column added |
| Module 01's `PermissionSeeder` | unchanged. Module 03's permissions live in their own seeder, so neither module can revoke the other's grants. |
| Module 02's `AcademicPermissionSeeder` | unchanged |
| Existing `staff` rows | untouched; the new columns are nullable or have an `ACTIVE` default |
| `Role` enum | unchanged. No new role; `staff_type` remains a classification of `STAFF`. |

---

## 9. Out of scope — confirmed not built

As promised in the audit, and verified absent from `git status`:

`StaffRepository`, `StaffManager`, `StaffFactoryService`, `StaffHelper`,
`StaffTransformer`, `StaffQueryBuilder`, `StaffPermissionService`, `StaffObserver`,
`CreateStaffAccountRequest`, `StaffSearchRequest`, a base controller, a `departments` table, a
`designations` table, events, listeners, DTOs, interfaces, model scopes for filtering (the
service owns the query), a `staff.delete` permission, a `DELETE` route, and any staff data on
the `users` table.

---

## 10. How to run it

```bash
# schema + seed (local/testing only for the two account seeders)
php artisan migrate:fresh --seed

# the suite
php artisan test

# the six routes
php artisan route:list --path=staff

# style
php vendor/bin/pint
```

The development super admin's password is generated, not printed to a non-interactive log —
set `SUPER_ADMIN_PASSWORD` in `.env` and re-seed, as in Module 01. The two seeded staff
accounts are `teacher@example.test` and `clerk@example.test`, password `Password!123`, both
role `STAFF` (and therefore refused by every staff route, which is itself a useful check).

Endpoint reference: [../api/staff-management.md](../api/staff-management.md).
