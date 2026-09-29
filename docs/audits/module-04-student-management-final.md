# Module 04 — Student Management: Final Report

Status: **complete and verified, with one open cross-module issue (§7.0)**
Roll build: **407 tests, 1826 assertions, 0 failures** (was 329 before this module; **+78
tests**).

Architecture and decisions: [module-04-student-management-audit.md](module-04-student-management-audit.md)
Client reference: [../api/student-management.md](../api/student-management.md)

---

## 1. What was built

The pupil roll: identity and lifecycle. One table, four endpoints, three permissions.

```
GET    /api/v1/students             students.view     list, filtered, paginated
POST   /api/v1/students             students.create   add a pupil, no account
GET    /api/v1/students/{id}        students.view     read one
PUT    /api/v1/students/{id}        students.update   whole-record amend
```

Route count **43 → 47**.

**18 new files, 2377 lines**, of which 904 are tests.

| Area | Files |
|---|---|
| Enums | `Gender`, `StudentStatus` |
| Model | `Student` (+ `User::student()`) |
| Schema | `create_students_table` |
| Factory | `StudentFactory` |
| Seeders | `StudentPermissionSeeder`, `StudentSeeder` (+ `DatabaseSeeder`) |
| HTTP | `StudentController`, `StudentListRequest`, `StoreStudentRequest`, `UpdateStudentRequest`, 2 concerns, `StudentResource` |
| Service | `StudentService` |
| Tests | 3 files, 78 tests |
| Docs | this report, the audit, `docs/api/student-management.md`, `docs/api/README.md` |

**Modified:** `routes/api.php`, `app/Models/User.php`, `database/seeders/DatabaseSeeder.php`,
`tests/TestCase.php`, `tests/Pest.php`, `tests/Unit/EnumsTest.php`, `docs/api/README.md`, and
two pre-existing test files (§4.4) — 9 files.

---

## 2. Verification

| Check | Result |
|---|---|
| `php artisan test` | **407 passed**, 1826 assertions, 0 failures |
| `./vendor/bin/pint --test` | **passed** |
| `php artisan migrate:fresh --seed --force` | **clean** — 20 migrations, all seeders |
| `php artisan route:list` | 47 routes, 4 of them students |
| Live HTTP (24-step run against `artisan serve`) | **all as expected** |
| SQL Server (`sqlsrv`) | **never exercised** — see §7.0, this is why it holds a live defect |
| `git status` | `.env` restored; smoke-test credential removed |

### 2.1 Live HTTP

A real server, real bearer tokens, real HTTP status codes:

| # | Request | Expected | Got |
|---|---|---|---|
| 1 | `GET /students` no token | 401 | 401 |
| 2 | `POST /auth/login` super admin | 200 | 200 |
| 3 | `GET /students` | 200, total 2 | 200, total 2 |
| 4 | `POST /students` | 201 | 201 |
| 5 | `POST /students` no `first_name` | 422 | 422 |
| 6 | `POST /students` `"  stu-0777 "` | 201, `STU-0777` | 201, `STU-0777` |
| 7 | `POST /students` duplicate number | 422 | 422 |
| 8 | `POST /students` `gender: OTHER` | 422 | 422 |
| 9 | `POST /students` future dob | 422 | 422 |
| 10 | `POST /students` with `user_id: 1` | 201, `user_id` empty | 201, `user_id` empty |
| 11 | `GET /students?search=%25` | 0 matches | 0 matches |
| 12 | `GET /students?status=EXPELLED` | 422 | 422 |
| 13 | `GET /students/999999` | 404 | 404 |
| 14 | `PATCH /students/{id}` | 405 + `Allow` | 405, `Allow: GET, HEAD, PUT` |
| 15 | `DELETE /students/{id}` | 405, pupil survives | 405, pupil survives |
| 16 | `PUT` status `WITHDRAWN` | 200 | 200 |
| 17 | `PUT` after departure | 422 | 422 |
| 18 | `PUT` reinstate after departure | 422 | 422 |
| 19 | `GET` a departed pupil | 200, readable | 200, `WITHDRAWN` |
| 20 | `GET /students?per_page=500` | 422 | 422 |
| 21 | `GET /students` as a **pupil** | 403 | 403 |
| 22 | `GET /auth/me` as a pupil | 200 | 200 |
| 23 | `GET /students` as a **registrar** | 200 | 200 |
| 24 | registrar records a withdrawal | 200 | 200 |

`nullOnDelete` verified live: a pupil whose `users` row was deleted **survived**, with
`user_id` becoming `null` and the roll count unchanged.

Also verified live: search escaping (`%` returns 0, not the whole roll), `status` and `gender`
filters, surname-less pupils sorting last, and `REGISTRAR` holding exactly
`students.create`, `students.update`, `students.view`.

---

## 3. Test breakdown — 78 new tests

**`StudentManagementTest` — 33.** Create with no account, derived numbers, client-supplied
numbers, **per-insert reservation distinctness (§4.5)**, **transactional rollback of a failed
derived write**, **reservation unobservable to a client**, optional surname/gender, required
first name, future dob, unknown gender, ignored status on create, show, 404, alphabetical
ordering, surname-less last, amend, whole-record write, 405 for `PATCH` and `DELETE`, no delete
permission, `account_status` present-but-null, exposure list, `nullOnDelete`, one-to-one
account link, `User::student()`, absence of all academic and credential columns,
nullable-vs-`NOT NULL` asymmetry.

**`StudentStatusTest` — 16.** Active on create, inactive and back, graduated, withdrawn, full
read-only freeze, no reinstatement, no terminal swap, retry refused, retry safe for a
departed pupil's counterpart, status omitted, unknown value, departed pupil still readable,
**account untouched by a status change**, registrar may record a departure, 422 not 500.

**`StudentSecurityAndFilterTest` — 26.** Unauthenticated, no-permission, suspended, the three
granted roles, pupil refused the roll, per-action permissions, seeded permission set, no
cross-module revocation, name/number/partial search, no match, **wildcard escape**, `status`
and `gender` filters, combined filters, enum error messages, pagination cap, filter
persistence, `user_id` ignored on create **and amend**, no account created, no school write,
exposure list, list envelope.

**`EnumsTest` — +4.** Lifecycle values and terminality, distinctness from `UserStatus`,
gender pair.

---

## 4. Defects found by these tests, and what was done

Five real bugs, all found by tests rather than by reading. Recording them because four of the
five are the kind of thing that looks correct in review, and one of them was **shipped in this
module's first version and caught only in a second pass**.

### 4.1 A client-supplied student number was silently discarded

`StudentService::create()` set the placeholder, then unconditionally overwrote the number with
the PK-derived one — so a school's own numbering scheme was validated, reported as accepted,
and thrown away.

**Fixed in the service, not the test.** A supplied number is now honoured; only the derived
path uses the placeholder and the second statement. A school with its own scheme can use it.

### 4.2 The service docblock claimed idempotence the code could not deliver

`setStatus()` had a branch allowing a repeat of the status a pupil already had, documented as
"a retried request is safe". `update()` refuses every amend on a terminal record **before**
`setStatus()` is reached, so the branch was unreachable — the claim was false.

Rather than add a carve-out, the rule was made what the comment already implied and Module 03
already does: **a departed pupil's record is read only in full.** The misleading branch was
removed, the docblock rewritten to explain the decision and why the alternative was rejected,
and the tests now pin both the refusal and the fact that retry idempotence still holds for
pupils who have not left.

### 4.3 Search wildcards were not escaped

`search=%` produced `%%%` and returned the **entire roll** — a box meant to narrow the list was
a way to dump it. `search=_` had the same effect for single characters.

**Fixed** by escaping `!`, `%` and `_` in the term and declaring `ESCAPE '!'`. `!` rather than
`\` because MySQL treats a backslash inside a string literal as an escape of its own, so
`ESCAPE '\'` is not portable across the four supported drivers. Covered for `%`, `_`, and a
normal substring to prove the escaping did not break ordinary search.

### 4.4 Two pre-existing tests used `students.view` as a "future permission" placeholder

`AuthorizationTest` and `StaffTypeTest` both borrowed `students.view` to mean *a permission no
module owns yet*. Module 04 made it real, and `AuthorizationTest` began failing with a 200
where a 403 was expected.

This is a **latent trap worth recording**: a placeholder that a later module adopts does not
fail loudly. The test kept passing while quietly meaning something else, and only surfaced
because the new seeder granted it to `REGISTRAR`. Both were moved to genuinely unowned names
(`graduation_records.view`, `attendance.record`), the throwaway route's gate changed to match,
and the reason is commented at each site so the next module does not repeat it.

### 4.5 The reservation number was a fixed string, so two pupils added at once would 500

**This one was introduced by this module and shipped in its first version.** It was found on a
second review pass, not by the original test suite.

`student_number` is unique, and the derived number depends on the id the insert produces — so
the row has to be written with *something* first and rewritten immediately after. The first
version reserved the fixed string `STU-PENDING`, justified in a comment that read:

> "This is a value no derived number can ever equal, and it is replaced before the request
> returns."

Both halves are misleading. No *derived* number can equal `STU-PENDING` — true, and the wrong
comparison. The thing that collides is another **reservation**: two pupils added at the same
moment both insert `STU-PENDING`, the second one dies on the unique index, and the client gets
a **500** for a request that was entirely valid. And "replaced before the request returns" is
not a guarantee — with no transaction, an error between the two statements commits a pupil
whose public number really is `STU-PENDING`, permanently.

The existing test — *"derives a number from the pupil id and not from a count, so concurrent
creates cannot collide"* — could not catch either half, because it only calls
`deriveStudentNumber()` directly and checks the format. It proved the *final* numbers differ
and said nothing about the value the row carried in between.

Three tests now cover it, and each was confirmed to fail against the broken code:

| Test | Property it pins |
|---|---|
| *reserves a different number for every insert* | captures the value at every insert via a `creating` hook and asserts no two are equal. Real concurrency is not simulated and does not need to be: the collision happens **iff** two inserts write the same reservation, which one process making two inserts can observe. |
| *never leaves a pupil holding a reservation number, even if the derived write fails* | injects a failure on the second write to the table and asserts the pupil does not survive. A transaction is the only thing that makes that true. |
| *never exposes a reservation number to a client* | the reservation is unobservable, not merely unlikely to be shown, on both the derived and supplied paths. |

**Fixed** with a per-insert UUID reservation inside a transaction. `NULL` was rejected as the
substitute — see §7, and it is the more interesting half of this bug, because `NULL` is what
the schema's nullability invites and it is wrong on one of the four configured drivers.

---

## 5. Design notes worth carrying forward

- **A pupil is not a login.** `user_id` is nullable, `nullOnDelete`, and settable by no
  payload. The absence of the key is the protection: `students.update` goes to `REGISTRAR`,
  so a settable `user_id` would be an account takeover wearing a field that looks like record-keeping.
- **A departed pupil is read only in full, but still readable.** Refusing to amend is not the
  same as hiding, and surviving is exactly why departure is a status rather than a deletion.
- **The roll has no placement column**, and must not grow one. The first academic module needs
  an `enrollments` table.
- **Search is case-folded and wildcard-escaped**, both explicit, both portable across the four
  supported drivers.
- **Ordering is surname, then first name, then id** — with the id tiebreak so paging a roll
  with duplicate names cannot show one pupil twice and skip another.
- **The two enums are distinct on purpose.** `status` and `account_status` overlap in name
  (`ACTIVE`/`INACTIVE`) and are separate questions; a test asserts they cannot drift together.

---

## 6. Files changed outside the module

Four, all necessary, all noted so a reviewer is not surprised:

| File | Why |
|---|---|
| `routes/api.php` | the 4 routes |
| `app/Models/User.php` | `User::student()` |
| `database/seeders/DatabaseSeeder.php` | seeder registration, order load-bearing |
| `tests/TestCase.php` | seeds `StudentPermissionSeeder` after the others — `PermissionSeeder`'s `sync()` would otherwise revoke the grants and every students route would 403 with every table looking correct |
| `tests/Pest.php` | Student helpers; throwaway-route gate (§4.4) |
| `tests/Unit/EnumsTest.php` | +4 enum tests |
| `tests/Feature/Auth/AuthorizationTest.php` | placeholder permission (§4.4) |
| `tests/Feature/Auth/StaffTypeTest.php` | placeholder permission (§4.4) |
| `docs/api/README.md` | index + build order |

---

## 7. Known limitations, stated rather than engineered away

### 7.0 A portability defect inherited from Module 02 — **open, needs a decision**

Found while fixing §4.5, and **not confined to this module**.

**SQL Server treats `NULL` as a value in a UNIQUE index, not as "no value"** — it permits
exactly one null row per column. SQLite, MySQL and PostgreSQL treat nulls as distinct and
permit any number. The project configures all four, and has never run against SQL Server
(development and `phpunit.xml` are both SQLite), so the suite cannot detect this.

| Column | Consequence on SQL Server |
|---|---|
| `terms.active_marker` | **a school cannot record a third term** |
| `academic_sessions.active_marker` | one active session + one inactive; the third fails |
| `students.user_id` | **one pupil without a portal account** — the module's central premise fails |
| `staff.staff_number` | one staff member with no number |
| `students.student_number` | worked around here by the UUID reservation (§4.5) |

Root cause is a single sentence in the Module 02 audit: *"All of SQLite, MySQL, PostgreSQL and
SQL Server treat `NULL` as distinct in a unique index."* The first three are right; the fourth
is not, and `active_marker` was built on that sentence. Module 03's audit names only three
drivers for `staff_number` — correctly, and the discrepancy is how the bad claim got copied
forward instead of re-derived.

**Not fixed here.** It changes two delivered modules and the options are not equivalent:
drop `sqlsrv` from the supported set; keep four drivers and replace `active_marker` with
something portable (which reopens the DDL question Module 02 closed); or keep `sqlsrv` for
reads only. That is the owner's call, not a drive-by fix. Nothing in Module 04 depends on the
answer, and §4.5 is correct on all four drivers regardless.

### 7.1 The rest

1. **`gender` is `MALE`/`FEMALE`.** A pupil who is neither cannot be recorded; the options today
   are an inaccurate value or no value. The column is nullable, and widening is additive.
2. **`POST /students` creates nobody who can log in.** Intended (§3.1 of the audit), but it
   does mean a client needing an account in the same breath must wait for the portal module.
3. **`students.user_id` has no provisioning path.** A link can only be made by a test factory
   or a future module. That is deliberate, and it is the one thing most likely to be
   misunderstood as a gap.
4. **A departed pupil's record cannot be corrected through the API at all,** including a
   misspelled name. Deliberate, and the escape is an authorised data migration.
5. **`has_account` is not a filter** although it would work here. Outside approved scope; noted
   in code and docs as a one-liner.
6. **No class/session filtering on the roll,** by design. Needs `GET /classes/{class}/students`
   once enrollments exist.
7. **No `students.status` permission.** Lifecycle is inside `students.update`. Defensible; the
   split is additive if wanted.

---

## 8. Nothing was committed

All work is in the working tree, per the standing instruction to commit only when asked.
`git status` shows **9 modified files and 15 untracked paths** (three of which are directories:
the controller, requests, service, student tests, and the three new docs).
