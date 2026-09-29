# Module 04 — Student Management: Architecture Audit

Status: **approved and implemented**
Scope: the pupil roll. Identity and lifecycle only.

This is the Stage 1 + Stage 2 record: what exists, what was decided, and why. The verification
results are in [module-04-student-management-final.md](module-04-student-management-final.md).

---

## 1. What was already in the codebase

Verified against the live schema and the filesystem, not assumed:

| Thing | State found | Consequence |
|---|---|---|
| `students` table | **did not exist** | Greenfield. No migration to adapt, no data to migrate. |
| `Student` model / factory / seeders / tests | none | Nothing to preserve or rename. |
| `students.*` routes, controller, service, requests, resource | none | Free to name consistently. |
| `Role::STUDENT` | **exists** | An authentication role from Module 01. Not a Student domain. |
| `UserFactory::student()` | **exists** | Creates a `User` holding the `STUDENT` role. No pupil record. |
| `Permission` docblock, `AuthServiceProvider`, `tests/Pest.php` | reference `students.view` | Module 01's worked **example**. Never a seeded permission. |
| `staff.user_id` | `NOT NULL`, `unique`, `cascadeOnDelete` | From Module 01. Module 03 inherited it. |
| Class / section / session structure | Module 02, academic-scoped | Placement lives in academic tables, not on people. |

`Role::STUDENT` and `UserFactory::student()` are the trap in this module's starting point.
Both sound like "students are already modelled". Neither is: they establish that a **login
can hold a pupil's role**, which says nothing about a pupil. Treating them as a head start
would have rebuilt the account model this module exists to avoid.

The four `students.view` references in Module 01 are the second trap. They are the exact name
this module adopted, so a grep for existing student code finds four hits that are all
comments or a throwaway test route. They confirmed the naming, nothing more.

---

## 2. The question the whole module turns on

**Is a pupil the same thing as a login?**

Module 03 answered *yes* and the constraint is still in the database: `staff.user_id` is
`NOT NULL`. A staff record cannot exist without an account, so Module 03 had to put the name
on `users`, accept `email` and `password` on create, and expose a dead `user_id` column on the
resource.

For pupils the answer is **no**, and the evidence is in the domain:

- A Nursery entrant has no email address.
- A child does not choose a password and cannot be made to keep one secret.
- The lifecycle runs **Student → Admission → Enrollment**. Identity comes *before* anything
  that would want a portal account. Requiring a login would invert that.
- Pupils outnumber staff, so a design that makes accounts a precondition is unusable for
  exactly the population most likely to need a simple record.

Everything below follows from that answer.

---

## 3. Decisions

### 3.1 `user_id` is nullable and never settable through the API — **approved, with a caveat in §6**

`students.user_id` → `nullable`, `unique`, `nullOnDelete`.

`POST /students` requires no `email` or `password` and creates no account.

> **Caveat.** `nullable()->unique()` does **not** mean "many rows may be null" on all four
> configured drivers. Under SQL Server a UNIQUE index treats NULL as a single comparable value
> and permits exactly one null row per column. On SQL Server, therefore, this table can hold
> **one** pupil without a portal account. The whole premise of this module — a pupil's identity
> is independent of their login — fails there. See §6.

**Why `nullOnDelete` is the opposite of `staff.user_id`'s `cascadeOnDelete`:** a pupil is a
person and outlives their portal login. Deleting the login must not delete the child. That is
a different reason from Module 03's, and it does not weaken as the schema grows.

**Why the relationship exists but no payload can set it:** `User::student()` and the column are
in place so a future portal module has something to write to. Exposing `user_id` now would be
offering a field that grants nothing yet — and would be actively dangerous, because Module 04
grants `students.update` to `REGISTRAR`, and a registrar could repoint a child's record at an
administrator's login and read the roll with their permissions. The absence of the key is the
protection; a deny-list of forbidden values is a list somebody has to remember to extend.

A test asserts `user_id` is `NOT NULL` on `staff` and nullable on `students`, so a later
"consistency" pass has to break a test rather than tidy silently.

### 3.2 The name lives on the pupil — **approved (implied by 3.1)**

`first_name`, `middle_name`, `last_name` on `students`, not a single `name` on `users`.

Module 03 could not make this choice: the account was mandatory, so the only place a name
could live was `users`. A pupil can exist with no account, so the name has to belong to the
pupil.

A linked account's `users.name` is a login-facing label, is not read for display here, and is
**not** kept in step with these fields.

`first_name` is required; the other two are not. A pupil with a single name is real, and
inventing a surname to satisfy a `NOT NULL` would put fabricated data on a child's permanent
record — the opposite of what a required field should guarantee.

Payoff: the roll search touches one table, where Module 03's has to reach through to `users`.

### 3.3 No academic placement, no admission number — **approved**

The `students` table has **no** `current_class_id`, `current_section_id`, `current_session_id`,
`class_id`, `section_id`, `session_id` or `admission_number`.

A pupil's placement is a decision about a *specific academic session*:

```
2024/2025 → JSS 2 → A
2025/2026 → JSS 3 → A
2026/2027 → SS 1  → B
```

Three rows in a future `enrollments` table, one row here. A `current_class_id` would be a
denormalised copy of the newest of those, and a copy is only as correct as the code maintaining
it — the first promotion that wrote the column but not the enrollment row would leave the roll
showing a child in a class they had left, with nothing able to say so.

`admission_number` is a different exclusion: it identifies one *admission attempt*, and a
child can have more than one. Admission numbering belongs to the admission module.

Module 02's class/section/session tables are scoped to academic years, not to people, so they
cannot be attached to a pupil without a session anyway.

Tests assert the absence of each of these columns, so adding one back is a deliberate edit to a
failing test.

### 3.4 `student_number`, derived from the pupil's own primary key — **approved**

Optional, nullable, unique, trimmed and upper-cased, `STU-` + zero-padded id.

Derived from the id rather than `count() + 1`, because `count() + 1` is wrong under
concurrency: two simultaneous creates read the same count, derive the same number, and the
loser's insert dies on the unique index as a **500**. A primary key cannot be read twice.

A client-supplied number **is** honoured. An earlier implementation validated it and then
overwrote it with the derived value, which is the worst of both — the client is told their
number was accepted and it is not on the record. That was caught by a test, not by reading.

**Deriving the number needs a second statement, and that is where the subtle bug in this module
lives.** The number depends on the id the insert produces, so the row must be written with
*something* in a unique column first. An earlier version reserved the fixed string
`STU-PENDING` for that instant, reasoning that no *derived* number could equal it — a true
statement about the wrong comparison. Two pupils added at the same moment both inserted
`STU-PENDING`, and the second died on the unique index as a 500. The same reasoning also
claimed the reservation was replaced "before the request returns", which is not a guarantee:
without a transaction, an error between the two statements commits a pupil whose public number
really is `STU-PENDING`, permanently.

The fix is a per-insert UUID reservation inside a transaction. `NULL` is *not* an acceptable
substitute — see §6. The property that matters is narrow and now tested directly: **no two
inserts ever write the same reservation value.**

### 3.5 Roll status — **approved**

`ACTIVE`, `INACTIVE`, `GRADUATED`, `WITHDRAWN`; `GRADUATED` and `WITHDRAWN` terminal.

**Two** terminal states, not one: "left the school" and "finished the school" are different
facts, and a single `LEFT` would lose the distinction worth keeping.

No `PENDING`: a pupil is not an application. An application is an **admission**, with its own
lifecycle in a future module.

`GRADUATED` and `WITHDRAWN` are mutually unreachable, not merely one-way. A withdrawn child did
not in fact graduate; correcting a genuine mistake is a data migration with someone who can
authorise it, not an ordinary amend.

### 3.6 Gender: nullable `MALE` / `FEMALE` — **approved**

Nullable throughout, with `null` meaning "not recorded".

**Stated limitation, not a considered policy.** This pair cannot represent a pupil who is
neither, and the only ways to record such a child today are an inaccurate value or no value at
all. It is the smallest model that records what the overwhelming majority of pupils are.

Widening is deliberately **not** pre-emptive: a value with no case behind it cannot be filtered,
and a filter over a list the enum does not define would be a guess. When it is needed the change
is additive and contained — add a case, widen the column — and nothing else moves, because every
reader goes through `values()` and validation uses `Rule::enum()`.

### 3.7 No delete endpoint — **approved**

`DELETE /students/{id}` is not registered; a `DELETE` gets 405 with `Allow: GET, HEAD, PUT`.
No `students.delete` permission.

A pupil is a child and a person. They leave the roll by being marked `WITHDRAWN` or
`GRADUATED`, and the record stays.

Module 03's reasoning was weaker and narrower — nothing referenced a staff record yet, so a
dependents guard could never fail. **This reasoning holds no matter what arrives later.** When
enrollment, attendance and result tables come, their `restrictOnDelete` keys become a second
line of defence, but they are not the reason this endpoint is absent.

### 3.8 No `activate` / `deactivate` endpoints — **approved**

A pupil's lifecycle is one orthogonal question, amended through the same `PUT` as the name.

Module 03 needed separate endpoints because **ending somebody's employment is a supervisory
decision** that must be grantable separately from editing a record. A child's roll status is
not: recording that a child moved away is a registrar's ordinary record-keeping. The terminal
rule, not a withheld permission, is what keeps the status honest.

Two extra routes and two extra permissions to fix a typo in a surname is a bad trade.

### 3.9 Three permissions, plural — **approved**

`students.view`, `students.create`, `students.update`; granted to `SUPER_ADMIN`, `ADMIN`,
`REGISTRAR`. Nothing to `STAFF` or `STUDENT`.

No `students.status` (§3.8) and no `students.delete` (§3.7).

**Plural** follows the project majority (`school.view`, `classes.*`, `terms.*`, `sections.*`)
and matches the `students.view` Module 01 already used as its example. Module 03's `staff.*` is
the outlier, left alone deliberately: renaming a seeded permission in a completed module is a
data migration and a breaking change to a live API for a consistency that is already 90% there.

**`REGISTRAR` holds all three.** RoleSeeder's own words: "Handles admissions, enrollment and
student records".

**`STAFF` holds nothing.** A teacher's need to know which class a pupil is in is a question
about an **enrollment**, not a licence to read and rewrite the roll.

**`STUDENT` holds nothing over the roll**, but keeps Module 01's `profile.*` self-service pair.
Verified live: a pupil account gets 403 on `/students` and 200 on `/auth/me`.

### 3.10 Filters: `search`, `status`, `gender` — **approved**

Plus the inherited `per_page` and `page`. Validated against `values()`, so the permitted values
appear in the error message.

**No academic filters** (§3.3). `GET /classes/{class}/students` is the endpoint that will answer
"who is in JSS 2 this session", and it will be honest about sessions in a way a roll filter
never can be.

**No `has_account`,** and this one is a judgement worth recording. Module 03's version was
honest but useless — `staff.user_id` is `NOT NULL`, so it returned everything or nothing. Here
`user_id` is nullable, so the same filter **would** return a real subset: "which pupils have no
portal login yet" is a genuine question, and one the registrar will ask *precisely because*
this module creates pupils without accounts. It is left out only because it was not in the
approved scope, not because it would not work. Noted in `ValidatesStudentFilters` and in the
API doc so it is a known one-liner rather than something to rediscover.

---

## 4. Deliberate absences

Not built, and why. Each would have been speculative.

| Not built | Reason |
|---|---|
| `Repository`, `Manager`, DTO, interface layer | One table and four endpoints. Module 03 showed a service is the right weight; nothing above it is. |
| Observer / events on `Student` | Nothing observes a pupil yet. The terminal rule is a service rule and belongs where it cannot be bypassed. |
| `StudentPolicy` | Routes are permission-gated, as in every other module. A policy would duplicate that. |
| `has_account` filter | Real and useful, but outside approved scope (§3.10). |
| PATCH support | A whole-record `PUT` that silently behaved partially is worse than a 405. |
| `students.delete` | No endpoint (§3.7). |
| `students.status` | No separate transition (§3.8). |
| Account provisioning | The portal module's job (§3.1). |
| `email` on the resource | A minor's personal data; `users.view` is Module 01's business. |
| Model events disabled in `DatabaseSeeder` | Module 02's `active_marker` hooks depend on them. |
| Pagination/sort traits or a QueryBuilder helper | Three modules have not needed one. A shared abstraction invented at the third use is premature. |

---

## 5. What this module owes the next one

- `students.user_id` is nullable, unique, `nullOnDelete`, and writable **only** by a future
  portal module. Do not make it `NOT NULL` "for consistency with staff" — the asymmetry is
  correct and is pinned by a test.
- The roll has no placement column, and must not grow one. The first academic module needs an
  `enrollments` table, not a `students` edit.
- A pupil's `status` freezes the whole record. If a future requirement needs a departed pupil's
  details corrected, that is a deliberate, authorised data migration — not a relaxation of this
  rule.
- `gender` needs a third case before it can record a pupil outside `MALE`/`FEMALE`. Additive
  and contained, but it has to be done on purpose.

---

## 6. A portability defect inherited from Module 02 — **open, needs a decision**

This was found while fixing §3.4, and it is not confined to this module.

**SQL Server treats `NULL` as a value in a UNIQUE index, not as "no value".** A unique index
there permits exactly **one** null row per column. SQLite, MySQL and PostgreSQL all treat nulls
as distinct and permit any number.

The project configures four drivers (`sqlite`, `mysql`, `pgsql`, `sqlsrv`) and Module 02
explicitly rejected driver-specific DDL *because* four are configured. So this is in scope, not
hypothetical. Nothing has ever run against SQL Server: development and `phpunit.xml` are both
SQLite, so the suite cannot detect it.

Every `nullable()->unique()` column in the schema is affected:

| Column | Consequence on SQL Server |
|---|---|
| `academic_sessions.active_marker` | one active session **+ one** inactive; the third insert fails |
| `terms.active_marker` | **a school cannot record a third term** |
| `staff.staff_number` | one staff member with no number |
| `students.student_number` | see §3.4 — worked around by a UUID reservation |
| `students.user_id` | **one pupil without a portal account** |

The `active_marker` rows are the severe ones. Module 02's audit states that "all of SQLite,
MySQL, PostgreSQL and SQL Server treat `NULL` as distinct in a unique index". The first three
are correct; the fourth is not, and that sentence is the single root cause. The same audit also
records that `staff.staff_number` is nullable-and-unique "under SQLite, MySQL and PostgreSQL" —
naming three drivers, not four. Module 03 got that right, and the discrepancy is why the wrong
claim was copied forward rather than re-derived.

This is **not fixed here.** It changes two delivered modules and needs a decision, because the
options are not equivalent and the choice belongs to the owner:

1. **Drop `sqlsrv` from the supported set** and say so in the README. Cheapest, and makes every
   current column correct as written.
2. **Keep four drivers and replace the `active_marker` mechanism** with something portable —
   which cannot be a plain `Blueprint`, and reopens the DDL question Module 02 closed.
3. **Keep `sqlsrv` for reading, not for the write paths that depend on these columns.** Partial,
   and likely to fail confusingly later.

Nothing in Module 04 depends on the answer, and the §3.4 fix is correct on all four drivers
either way.

---

## 7. Verification

See [module-04-student-management-final.md](module-04-student-management-final.md) for the
test counts, the live HTTP run, and the `Pint` / migration results.
