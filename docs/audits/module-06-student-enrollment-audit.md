# Module 06 — Student Enrollment: Architecture Audit

Status: **implemented**
Scope: the authoritative academic placement - student, academic session, class, section - and
its own narrow lifecycle.

---

## 1. What was already in the codebase

Verified by repository-wide search before any code was written:

| Thing | State found | Consequence |
|---|---|---|
| `enrollments`/`student_enrollments` table, model, service, controller, requests, resource, tests | none | Greenfield. |
| `current_class`/`current_section`/`current_session` anywhere | none as columns; only as comments explaining their deliberate absence | Confirms rather than contradicts the brief's non-negotiable rule 7. |
| `promotion` | none | Every hit was a forward-looking comment (`Student.php`, `AcademicSessionStatus.php`, seeders) explaining that a future module owns it. Nothing to reconcile. |
| `ClassLevel 1—n SchoolClass 1—n Section` | exists, each with its own `CatalogStatus` and `scopeSelectable()` | Reused as-is for every class/section validation; no new hierarchy introduced. |
| `Admission.entry_class_level_id` | exists, explicitly documented as "not an enrollment" | Confirms Admission has no class/section FK to reuse or conflict with. |
| `Admission.student_id` | nullable, system-set only by `AdmissionService::admit()` | The only existing Admission↔Student link; Enrollment adds no second one. |
| `StudentStatus` (`ACTIVE`/`INACTIVE`/`GRADUATED`/`WITHDRAWN`) | exists | Reused directly as the enrollment eligibility check - not re-derived. |
| `AcademicSessionStatus` (`UPCOMING`/`ACTIVE`/`COMPLETED`) | exists, no `RETIRED` state | Reused for the "not COMPLETED" session-eligibility rule, identical to Module 05's own rule. |
| `restrictOnDelete` convention | established (`terms.academic_session_id`, `classes.class_level_id`, `admissions.academic_session_id`) | Followed for all four of `enrollments`' foreign keys. |
| `Rule::unique(...)->where(...)` scoped-uniqueness technique | established (`ValidatesTerm::termNumberUniqueness()`) | Reused verbatim for "one enrollment per student per session". |
| Cross-record checks needing a loaded relation live in the service, not the request | established (`TermService::assertDatesInsideSession()`) | Followed for the class-level-active check and the enrollment-date-in-session check. |
| `Model::unguarded()` inside factory creation | Laravel core behaviour, confirmed by reading `AdmissionFactory`'s own reliance on it | Let `EnrollmentFactory` set placement fields and `status` directly despite neither being in `$fillable`. |

Nothing contradicted the brief's assumed shape. The one place a deliberate choice departs from
the brief's suggested defaults is the enrollment status set (§4) and the admission dependency
(§5) - both documented below, per non-negotiable rule 19.

---

## 2. Files

### 2.1 Created

| File | Why |
|---|---|
| `database/migrations/2026_09_30_090000_create_enrollments_table.php` | The one new table. |
| `app/Enums/EnrollmentStatus.php` | `ACTIVE`/`WITHDRAWN`/`CANCELLED`. |
| `app/Models/Enrollment.php` | The placement record; four `belongsTo` relations; no placement field is mass-assignable. |
| `app/Services/Enrollment/EnrollmentService.php` | Every business rule: class-level-active check, session-date-range check, the race-safe unique-index catch, the two terminal transitions. |
| `app/Http/Requests/Enrollment/{Store,Update,List,Decide}EnrollmentRequest.php` | One per real endpoint shape. |
| `app/Http/Requests/Enrollment/Concerns/{ValidatesEnrollmentRecord,ValidatesEnrollmentFilters}.php` | Shared rule sets, split because Store and Update do **not** share one full rule set - see §6. |
| `app/Http/Resources/EnrollmentResource.php` | Explicit field list; nests `StudentResource`, `AcademicSessionResource`, `SchoolClassResource`, `SectionResource`. |
| `app/Http/Controllers/Api/V1/Enrollment/EnrollmentController.php` | Six actions, no `destroy()`. |
| `database/seeders/EnrollmentPermissionSeeder.php` | Five permissions, `syncWithoutDetaching()`. |
| `database/factories/EnrollmentFactory.php` | Test data only; builds a genuinely valid hierarchy by default. |
| `tests/Feature/Enrollment/{EnrollmentManagementTest,EnrollmentWorkflowTest,EnrollmentSecurityAndFilterTest,EnrollmentAdmissionIntegrationTest,EnrollmentIntegrityTest}.php` | See §10. |
| `docs/api/enrollment-management.md` | Client-facing reference, matching Modules 04/05's doc shape. |
| `docs/audits/module-06-student-enrollment-audit.md` | This file. |

### 2.2 Modified

| File | Change | Why |
|---|---|---|
| `routes/api.php` | Added the `enrollments` route group. | New endpoints. |
| `database/seeders/DatabaseSeeder.php` | Added `EnrollmentPermissionSeeder::class` after `AdmissionPermissionSeeder::class`. | Ordering is load-bearing, exactly as documented for every seeder before it. |
| `tests/TestCase.php` | Added `EnrollmentPermissionSeeder::class` to the seeded baseline. | Every feature test now starts from a baseline that includes Module 06. |
| `tests/Pest.php` | Added Enrollment helpers (`enrollmentCreatePayload`, `activeEnrollment`, `decidedEnrollment`, `eligibleStudent`, `eligibleSession`, `activeSection`), following the existing per-module helper-block convention. | Test ergonomics only; no production code touched. |

### 2.3 Kept unchanged

Everything in Modules 01–05: `Student`, `Admission`, every academic model/service, `User`,
`Role`, `Permission`, the Gate wiring, `ApiResponse`, `ListRequest`, `BusinessRuleViolation`.
`EnrollmentService` only *reads* `Student`, `AcademicSession` and `SchoolClass` - it calls
nothing on `AdmissionService` and nothing on any Module 02 service.

### 2.4 Deliberately not created

| Rejected | Reason |
|---|---|
| `EnrollmentRepository` / `EnrollmentManager` / `EnrollmentQueryBuilder` | One table, one service - the same weight every prior module settled on. |
| `EnrollmentDTO` / `EnrollmentInterface` | Arrays in, Eloquent models out, matching every other service in the project. |
| `EnrollmentTransformer` | `EnrollmentResource` already is this. |
| `EnrollmentObserver` / `EnrollmentEvents` / `EnrollmentListeners` | Nothing in this project observes model events (`DatabaseSeeder`'s own docblock explains why they stay enabled but unused). The terminal rule and the class-level check are service rules, and belong where they cannot be bypassed. |
| `EnrollmentFactoryService` / `EnrollmentPermissionService` | The Gate already resolves permissions from the database; a wrapper service around it adds no behaviour. |
| `EnrollmentPolicy` | Every route in this project is permission-gated middleware. There is also no per-owner scoping rule for Enrollment to express (§7.1), so a policy would have nothing extra to check. |
| A `class_id`/`section_id` transfer or "move" endpoint | Explicitly out of scope - see §6. |
| An enrollment reference/identifier number | Nothing needs one; `(student, session)` is already unique and filterable. |
| A `COMPLETED` enrollment status | Nothing observes "session ended" as an event - see §4. |
| A `search` filter | No text field exists on this table to search - see the API doc §3.1. |
| Promotion, Results, Subjects, Attendance | Explicitly future modules; not touched. |

---

## 3. Domain boundaries

```
Student     = persistent identity. Owns first/last name, gender, dob, roll status. No
              placement field, before this module or after it.
Admission   = the decision that may (or may not) precede a Student existing. Owns its own
              applicant snapshot and decision lifecycle. No placement field.
Enrollment  = THIS module. The sole authoritative record of where a Student sits - which
              class, which section, for which academic session. Owns enrollment_date, its own
              ACTIVE/WITHDRAWN/CANCELLED lifecycle, and nothing about the student's identity or
              the admission that may have led here.
```

`Enrollment` has no foreign key to `admissions` at all - see §5. It has foreign keys to
`students`, `academic_sessions`, `classes` and `sections`, and to nothing else.

---

## 4. The enrollment lifecycle decision

Adopted: `ACTIVE → WITHDRAWN | CANCELLED`, both terminal.

**Not adopted:** the brief's example four-state `ACTIVE/COMPLETED/WITHDRAWN/CANCELLED`.

There is no `COMPLETED`. The reasoning is the same method Module 05 used to reject
`APPLIED`/`UNDER_REVIEW`: a status is only real if something sets it. `COMPLETED` would need a
trigger - a scheduled job noticing a session's end date has passed, or a hook run when a
session transitions to `COMPLETED` - and this project has neither anywhere. Manufacturing one
here, for one column, would be exactly the "unnecessary architecture" the brief warns against.
A past enrollment is already legible as history through the **academic session's own** status:
once `academic_sessions.status = COMPLETED`, every enrollment that names it is unambiguously
historical, without needing a second, redundant flag copied onto each of those rows. `ACTIVE`
in this schema means "this placement was never withdrawn or cancelled" - a fact independent of
whether the calendar has moved on.

`WITHDRAWN` and `CANCELLED` are both terminal and kept distinct - the same reasoning Module 04
applied to `GRADUATED`/`WITHDRAWN` and Module 05 applied to `REJECTED`/`WITHDRAWN`: one is a
real historical event (the student left this placement), the other is a data-correction (the
row should not have existed). Collapsing them would lose "how many of this class's placements
were real versus mistaken entries" - a genuine reporting distinction, and the reason `CANCELLED`
exists at all is to be the delete-replacement (§9).

**Transition rule** (`EnrollmentService::assertActive()`): only `ACTIVE` may move, to either
terminal state; no terminal state may move anywhere, including back to `ACTIVE`. Repeating a
transition is refused, not treated as an idempotent success - matching Module 05's
one-shot-decision posture rather than Module 03's reversible activate/deactivate toggle, because
ending a placement is a fact about a moment, not a switch.

---

## 5. Admission dependency: not required

**Decision: `POST /enrollments` does not require, check for, or reference an `Admission`
record.**

The brief explicitly flags this as a decision to make carefully rather than assume: *"do not
automatically require an admission record if Module 05's architecture intentionally supports
students who do not require admission."* Module 05's own architecture does exactly that -
`POST /students` (Module 04) remains a fully independent, unremoved path to a `Student` with no
`Admission` row at all, and nothing in Modules 04 or 05 was changed to close it. Requiring an
`Admission` here would therefore not be enforcing an existing rule; it would be **inventing a
new one** that silently breaks an existing, still-live capability.

Instead, `EnrollmentService` asks exactly one eligibility question, and it is one Module 04
already answers: is `Student.status === ACTIVE`? A student admitted through Module 05's
`admit()` and a student added directly through Module 04 are, once they exist, indistinguishable
inputs to this module - verified directly in
`EnrollmentAdmissionIntegrationTest`.

This is a deliberate, documented divergence from the brief's sketched default
(`Admission → ADMITTED → Enrollment allowed`), per non-negotiable rule 19: the actual codebase's
architecture, not the brief's suggested pipeline, is what governs.

---

## 6. Update policy: why Store and Update do not share one rule set

Every prior module's Store/Update pair validated the **same** fields, differing only in whether
a status key was added on Update (Student, Staff) or never added at all (Admission). Enrollment
breaks that pattern on purpose: `UpdateEnrollmentRequest` has no key for `student_id`,
`academic_session_id`, `school_class_id` or `section_id` - not merely unvalidated, structurally
absent from `ValidatesEnrollmentRecord::enrollmentDetailRules()`, which is the only rule set the
amend request uses.

This follows directly from the brief's own caution: *"changing section may represent an actual
academic movement rather than a simple CRUD update... if a movement between classes/sections
requires a separate operation, implement it as a domain operation rather than treating it as
ordinary CRUD."* Module 06 does not build that domain operation - transfer/class-change is
explicitly out of scope (brief: *"Do NOT automatically implement a complex transfer system"*) -
so today there is **no path at all**, not a restricted one, to changing a placement once it is
created. `PUT` is left with exactly the two fields that are unambiguously corrections rather than
academic events: `enrollment_date` and `notes`.

---

## 7. Class/section/session/student validation

### 7.1 Class hierarchy integrity

- `school_class_id` must exist and be `CatalogStatus::ACTIVE` (a declarative `Rule::exists`).
- `section_id` must exist, be `ACTIVE`, **and** belong to the same class - expressed
  declaratively as `Rule::exists('sections', 'id')->where('school_class_id', $this->input('school_class_id'))`,
  so a section from an unrelated class is refused at validation, never trusted from the client.
- The class's own **class level** must also be `ACTIVE` - this cannot be expressed as a plain
  column `where()` without a join, so it is checked in `EnrollmentService::assertClassLevelActive()`
  against a loaded `SchoolClass::with('classLevel')`, the identical layering `TermService` uses
  for its own date-range check that needs a loaded `AcademicSession`.

### 7.2 Session eligibility

`academic_session_id` must exist and not be `COMPLETED` - the identical `Rule::exists(...)
->whereNot('status', ...)` technique Module 05 already established for the same reason. Both
`UPCOMING` and `ACTIVE` sessions are accepted, so a school can place students ahead of a new
year's start.

`enrollment_date` must additionally fall within the **named session's own** `start_date`/
`end_date` - checked in the service against a loaded `AcademicSession`, mirroring
`TermService::assertDatesInsideSession()` exactly.

### 7.3 Student eligibility

`student_id` must exist and the student's `status` must be `StudentStatus::ACTIVE` - expressed
declaratively as `Rule::exists('students', 'id')->where('status', 'ACTIVE')`. `INACTIVE`,
`GRADUATED` and `WITHDRAWN` are all refused uniformly. A school that wants to enroll a
currently-`INACTIVE` pupil reactivates them through Module 04's own `PUT /students/{id}` first;
this module does not grow a second opinion about a status it does not own.

### 7.4 One enrollment per student per session

Enforced twice, per the brief's explicit instruction to use both layers:

1. **Application level**: `Rule::unique('enrollments', 'student_id')->where(fn ($q) => $q->where('academic_session_id', ...))`
   on the `student_id` field of `StoreEnrollmentRequest` - the identical scoped-uniqueness
   pattern `ValidatesTerm::termNumberUniqueness()` already established for `term_number` scoped
   to a session. This is the path that answers a normal duplicate attempt with a clean `422`.
2. **Database level**: `unique(student_id, academic_session_id)` on the `enrollments` table -
   the race-safe backstop. `EnrollmentService::create()` wraps the insert in a
   `try/catch (QueryException)` and converts a `SQLSTATE 23000` (the portable integrity-violation
   code across all four configured drivers) into the same `BusinessRuleViolation` message,
   verified directly by calling the service twice in a row, bypassing the form request's own
   check, in `EnrollmentWorkflowTest`.

The rule is keyed on **(student, session)**, not **(student, session, class)** - enrolling the
same student into a *different* class in the same session is refused identically to enrolling
them into the same class twice, per the brief's own framing: *"one student + one academic
session = one authoritative enrollment."*

---

## 8. Database design

| Column | Type | Constraint |
|---|---|---|
| `student_id` | `foreignId` | `restrictOnDelete` |
| `academic_session_id` | `foreignId` | `restrictOnDelete` |
| `school_class_id` | `foreignId` (→ `classes`) | `restrictOnDelete` |
| `section_id` | `foreignId` | `restrictOnDelete` |
| `enrollment_date` | `date` | required |
| `status` | `string(20)`, indexed | default `ACTIVE` |
| `notes` | `text`, nullable | |
| `status_changed_at` | `timestamp`, nullable | |

**Indexes**: `unique(student_id, academic_session_id)` - the uniqueness rule, at the database
level. `index(school_class_id, section_id)` - the two roster questions (`GET
/enrollments?school_class_id=`, `...&section_id=`) this list actually answers. `status` is
indexed individually (its own column definition).

**Every foreign key is `restrictOnDelete`**, per the brief's explicit caution against
unexpected cascades. Verified directly, not merely asserted: `EnrollmentIntegrityTest` attempts
to delete an academic session, a class, and a section that each have a live enrollment against
them, and confirms **no cascade ever occurs** - the referenced record and the enrollment both
survive every attempt.

### 8.1 A known, accepted gap (documented, not fixed)

Module 02's own delete guards (`AcademicSessionService::delete()`, checking `isCurrent()`,
`isCompleted()`, `terms()->exists()`; `AcademicStructureService::deleteClass()`/`deleteSection()`,
checking for child records) were written before `enrollments` existed and do not know about it.
For an academic session or a section that has **no** other known dependent but **does** have an
enrollment, the delete attempt passes Module 02's own checks and then hits the database's
`restrictOnDelete` directly - which correctly **refuses the delete** (verified in
`EnrollmentIntegrityTest`), but surfaces as a generic `500` rather than Module 02's usual
friendly `422`, because the raw `QueryException` reaches the framework's catch-all handler
instead of a named business rule.

This is **not fixed here**, on the same principle Module 05's own audit applied to an identical
gap it found in the same two services (that audit's §7.1): fixing it means editing a Module 02
service, and *"treat Modules 01–05 as stable" / "do not refactor unrelated modules"* outweighs a
cosmetic error-status improvement when the database-level guarantee - no corruption, ever - already
holds regardless. A class with a live section is still caught by Module 02's own "still has
sections" guard with a clean `422` before ever reaching this gap, so the exposure is narrower
than it might first appear. Whoever next touches `AcademicSessionService`/
`AcademicStructureService` should add an `enrollments()->exists()` guard alongside the existing
ones.

---

## 9. Deletion policy

**No delete endpoint, no permission.** An enrollment is academic history that results,
attendance, promotion and report-card modules are all expected to reference - a stronger version
of the reasoning Modules 03–05 already gave their own records. `CANCELLED` is the
record-preserving replacement the brief explicitly suggests, used exactly where a hard delete
would otherwise have been reached for a mistaken row.

---

## 10. Permissions

`enrollments.view`, `enrollments.create`, `enrollments.update`, `enrollments.withdraw`,
`enrollments.cancel` - five, one per real operation, seeded by `EnrollmentPermissionSeeder` with
`syncWithoutDetaching()` after `AdmissionPermissionSeeder`.

`SUPER_ADMIN`, `ADMIN`, `REGISTRAR` hold all five - `RoleSeeder`'s own description names
"enrollment" explicitly, the identical justification Module 05 used to grant `REGISTRAR` the
full admission workflow rather than splitting a transition away from them.

`STAFF` and `STUDENT` hold none. A teacher's real need - knowing their own class's roster - is
not the same grant as browsing every student's placement in the school; a scoped answer is left
to whichever future module (Results/Attendance) actually needs to expose it, rather than
widening `enrollments.view` speculatively now.

---

## 11. Security review performed

- **IDOR**: there is no per-student or per-owner scoping in this module (every
  `enrollments.view`/`.update` holder can read/amend every enrollment, by design - a registrar
  processes the whole school's placements, not "their own"), so the meaningful IDOR boundary is
  "does a route parameter naming one enrollment's id ever let a write reach a *different*
  enrollment" - verified directly and confirmed it does not.
- **Mass assignment**: `Enrollment::$fillable` lists only `enrollment_date` and `notes`; every
  placement field and `status` has no key in *any* request, verified by injecting `status`,
  `student_id`, `academic_session_id`, `school_class_id`, `section_id` into every write payload
  and asserting none of them land.
- **Authorization**: full matrix tested (guest, `STAFF`, `STUDENT`, suspended account,
  per-permission detachment for `create`, `withdraw`).
- **Academic integrity**: a section from a different class, a retired class, a retired class
  level, a retired/completed session, an inactive/terminal student, and a duplicate
  student+session pair are each refused and each asserted not to have created a row.
- **Concurrency**: the unique-index race is exercised directly (bypassing the form request's own
  check) and confirmed to surface as the same `BusinessRuleViolation` message, not a raw `500`.
- **Foreign-key deletion behaviour**: verified directly rather than assumed - see §8.1.

---

## 12. What this module owes the next one

- `enrollments` is the **only** authoritative source for a student's placement. A future
  Promotion module creates a **new** row per promotion; it must never update
  `student_id`/`academic_session_id`/`school_class_id`/`section_id` on an existing one - there is
  no code path that does today, and none should be added.
- A future Results/Attendance module that needs "this student's current class" reads the
  `ACTIVE` enrollment for the current session, not a cached column anywhere.
- The Module 02 delete-guard gap (§8.1) is real but non-corrupting - worth a one-line fix
  whenever those services are next touched for another reason.
- A scoped "my class roster" endpoint for `STAFF` is a real, deferred need (§10) - additive when
  built, not a redesign of this module's permission grants.

---

## 13. Verification

See the final report delivered alongside this audit for the test run, `Pint`, and
migration/seed results.
