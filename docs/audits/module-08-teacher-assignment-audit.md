# Module 08 — Teacher, Class & Subject Assignment: Architecture Audit

Status: **implemented**
Scope: which teaching staff member is responsible for a class subject, for one academic
session.

---

## 1. What was already in the codebase

Verified by repository-wide search before any code was written:

| Thing | State found | Consequence |
|---|---|---|
| `teacher_assignments`/`teachers`/`subject_teacher` table, model, service, controller, requests, resource, tests | none | Greenfield. |
| `teacher`/`assignment` anywhere | only forward-looking comments (`class_subjects` migration: *"a future teacher assignment... will reference THIS table's id, not subjects.id directly"*, `SubjectPermissionSeeder`, `routes/api.php`) - all from Module 07's own work | Confirms rather than contradicts the module boundary. |
| `Staff` / `StaffType` (Module 03) | `TEACHING`/`NON_TEACHING`, deliberately not an authentication role | Reused directly; no new entity. |
| `EmploymentStatus` (Module 03) | `ACTIVE`/`INACTIVE`/`TERMINATED`, with `isActive()`/`isTerminated()`, deliberately separate from `UserStatus` | Reused directly for eligibility; `UserStatus` (login) deliberately not checked, mirroring `StaffService::deactivate()`'s own separation. |
| `ClassSubject` (Module 07) | session-**independent** - a standing curriculum fact; its own `CatalogStatus` independently of its parent class's status (a class subject can stay `ACTIVE` after its class is archived - verified by Module 07's own tests) | Directly informs the central session-dependency decision (§3) and the multi-level eligibility check (§5). |
| `active_marker` singleton technique (`AcademicSession`/`Term`/`School`) | a nullable-unique-boolean column, 1 on the single current record, NULL elsewhere, portable across all four configured drivers | Extended here, for the first time, to a **scoped** group (per `class_subject` + `session`) by including those columns in the same composite unique index, rather than inventing a new mechanism. |
| `Rule::unique(...)->where(...)` scoped-uniqueness technique | established (`ValidatesTerm`, `ValidatesEnrollmentRecord`, `ValidatesClassSubjectRecord`) | Reused verbatim for "at most one active assignment per class subject per session". |
| `RoleSeeder`'s description of `REGISTRAR` | *"Handles admissions, enrollment and student records"* | Staffing/teacher assignment is **not** named - directly informs the permission-grant deviation (§9). |
| `Model::unguarded()` inside factory creation | confirmed via `EnrollmentFactory`/`ClassSubjectFactory`'s own reliance on it | Let `TeacherAssignmentFactory` set all three reference fields and `status` directly despite none being mass-assignable through the API. |

Nothing contradicted the brief's assumed shape. The two places a deliberate choice departs from
the brief's own suggested defaults - session-dependency (confirmed as required, §3) and the
`REGISTRAR` permission grant (narrowed from the Module 05-07 pattern, §9) - are documented
below, per non-negotiable rule 19.

---

## 2. Files

### 2.1 Created

| File | Why |
|---|---|
| `database/migrations/2026_10_02_090000_create_teacher_assignments_table.php` | The one new table. |
| `app/Enums/TeacherAssignmentStatus.php` | `ACTIVE`/`ENDED`/`CANCELLED`. |
| `app/Models/TeacherAssignment.php` | The assignment; all three reference fields and `status` excluded from `$fillable`. |
| `app/Services/Staff/TeacherAssignmentService.php` | Placed under `Services\Staff` - see §4. |
| `app/Http/Requests/Staff/{Store,Update,List,Decide}TeacherAssignmentRequest.php` | One per real endpoint shape, under `Requests\Staff` for the same reason as the service. |
| `app/Http/Requests/Staff/Concerns/ValidatesTeacherAssignmentRecord.php` | Shared rule set, mirroring `ValidatesEnrollmentRecord`'s split between Store-only reference rules and shared detail rules. |
| `app/Http/Resources/TeacherAssignmentResource.php` | Explicit field list; nests `StaffResource`, `ClassSubjectResource`, `AcademicSessionResource`. |
| `app/Http/Controllers/Api/V1/Staff/TeacherAssignmentController.php` | Six actions, no `destroy()`. |
| `database/seeders/TeacherAssignmentPermissionSeeder.php` | Five permissions, `syncWithoutDetaching()`. |
| `database/factories/TeacherAssignmentFactory.php` | Test data only. |
| `tests/Feature/TeacherAssignment/{TeacherAssignmentManagementTest,TeacherAssignmentWorkflowTest,TeacherAssignmentSecurityAndFilterTest,TeacherAssignmentIntegrityTest}.php` | See §11. |
| `docs/api/teacher-assignment.md` | Client-facing reference. |
| `docs/audits/module-08-teacher-assignment-audit.md` | This file. |

### 2.2 Modified

| File | Change | Why |
|---|---|---|
| `routes/api.php` | Added the `teacher-assignments` route group. | New endpoints. |
| `database/seeders/DatabaseSeeder.php` | Added `TeacherAssignmentPermissionSeeder::class` after `SubjectPermissionSeeder::class`. | Ordering is load-bearing, exactly as documented for every seeder before it. |
| `tests/TestCase.php` | Added `TeacherAssignmentPermissionSeeder::class` to the seeded baseline. | Every feature test now starts from a baseline that includes Module 08. |
| `tests/Pest.php` | Added assignment helpers (`eligibleTeacher`, `assignmentCreatePayload`, `activeAssignment`, `decidedAssignment`), following the existing per-module helper-block convention. | Test ergonomics only; no production code touched. |

### 2.3 Kept unchanged

Everything in Modules 01–07, **including `Staff` and `ClassSubject` themselves** - no reverse
relation was added to either. `TeacherAssignmentService` queries and loads both directly
wherever it needs to, matching the discipline every prior module already established: only the
*owning* side of a new relationship gets a method.

### 2.4 Deliberately not created

| Rejected | Reason |
|---|---|
| A `Teacher` model/table (`teachers`, `teacher_profiles`, `teacher_accounts`) | Explicitly forbidden by the brief and unnecessary: `Staff` where `staff_type = TEACHING` already is this. |
| A `TEACHER` user role | `StaffType::TEACHING` already exists and is an employment classification, not an authentication concern - creating a role for it would duplicate Module 03's own architecture. |
| `TeacherRepository`/`AssignmentRepository`/`*Manager`/`*QueryBuilder` | One table, one service - the same weight every prior module settled on. |
| `TeacherDTO`/`AssignmentDTO`/`*Interface` | Arrays in, Eloquent models out, matching every other service in the project. |
| `TeacherTransformer` | `TeacherAssignmentResource` already is this. |
| `*Observer`/`*Events`/`*Listeners` | Nothing in this project observes model events. The class-hierarchy check and the unique-index race guard are service rules, and belong where they cannot be bypassed. |
| `TeacherPermissionService` | The Gate already resolves permissions from the database; a wrapper adds no behaviour. |
| A `TeacherAssignmentPolicy` | Every route in this project is permission-gated middleware; there is no per-owner scoping rule to express. |
| `teacher_id` on `subjects` or `class_subjects` | Explicitly forbidden by the brief; would collapse the reusable catalogue/offering into one specific teaching arrangement. |
| A dedicated `/reassign` endpoint | The brief's own reassignment scenario is fully expressed by composing two existing primitives (`end`, then `create`) - see §7. Building a third, coupled operation for a case the existing two already cover would be the speculative convenience endpoint the brief cautions against. |
| `GET /staff/{id}/assignments`, `GET /classes/{id}/teachers`, `GET /class-subjects/{id}/teachers` | The canonical list's `teaching_staff_id`/`class_subject_id` filters already answer both questions - explicitly not built per the brief's own instruction not to add redundant convenience endpoints. |
| A "primary teacher + assistants" team-teaching model | No stated requirement for it; would need an unrequested `role` column on the assignment. See §6 for the decision this replaces. |
| A `ClassTeacher`/form-teacher concept | Out of scope for this module - a genuinely different responsibility (class/section pastoral care vs. subject teaching) the brief explicitly says not to conflate with a `teacher_type` field. Left for a future module if ever required. |
| Assessments, Scores, Grading, Results, Promotion, Attendance, Timetable | Explicitly future modules; not touched. |

---

## 3. The central decision: session dependency

**`TeacherAssignment` is session-scoped; `ClassSubject` is not.**

The brief asks this to be evaluated carefully rather than assumed, and the audit of Module 07
settles it directly: `class_subjects` carries no `academic_session_id` because a curriculum
offering ("JSS 2 teaches Mathematics") is a standing fact, re-declared only when the curriculum
itself changes. **Who holds that teaching responsibility is not standing** - the brief's own
reassignment scenario (Teacher A, then later Teacher B, for the same class subject) is a
description of exactly the kind of fact that needs an academic-session dimension to be
historically honest: without one, "ended and replaced" and "never happened" would be
indistinguishable across years.

This makes `TeacherAssignment` the direct structural analogue of `Enrollment`: a session-scoped
placement of one entity (a teacher; a student) into an otherwise session-independent structure
(a class subject; a class-and-section). The same historical-integrity requirement that shaped
`Enrollment` shapes this table too - see §7.

---

## 4. Why the service and requests live under `Staff`, not a new namespace

`TeacherAssignmentService` sits in `App\Services\Staff`, and its Form Requests in
`App\Http\Requests\Staff`, rather than a new `App\Services\Assignment` or
`App\Services\Teacher`. This is fundamentally a Staff-module question - which staff member
teaches what - built entirely on Module 03's `Staff` and Module 07's `ClassSubject`. Giving it
a third, sibling namespace to either would imply a new, independent domain where none exists;
placing it under `Staff` says plainly that this module's subject is a fact *about* staff
members, not a new kind of thing.

---

## 5. Teacher eligibility

A single combined database check, expressed declaratively in the Form Request
(`Rule::exists('staff', 'id')->where('staff_type', 'TEACHING')->where('status', 'ACTIVE')`):

- The staff record must **exist**.
- Its `staff_type` must be **`TEACHING`** - a `NON_TEACHING` staff member cannot become a
  teacher merely by having their ID submitted, per the brief's explicit instruction.
- Its `EmploymentStatus` must be **`ACTIVE`** - `INACTIVE` (on leave) and `TERMINATED` staff are
  both refused uniformly, the identical rule `Enrollment` applies to `StudentStatus`.

**The staff member's login (`UserStatus`) is deliberately never checked.** Module 03 already
settled this separation for `StaffService::deactivate()`: employment status and login
capability are independent facts, and a suspended account does not stop someone being a
teacher of record on the school's books. Re-deriving a different answer here would contradict
a decision Module 03 already made.

---

## 6. Multiple teachers: one active assignment per class subject per session

**Decision: at most one `ACTIVE` teacher assignment may exist for a given
`(class_subject_id, academic_session_id)` pair at a time. Team teaching / assistant teachers
are not modelled.**

The brief presents this as a genuine choice ("if the project requires one primary teacher plus
assistants, design that explicitly... if only one teacher is required, enforce it") rather than
assuming an answer. Nothing in the brief or the existing codebase states a team-teaching
requirement, and introducing a `role` column (`PRIMARY`/`ASSISTANT`) to support an unrequested
case would be exactly the speculative architecture the brief warns against building "just in
case". The single-active-slot rule is also what makes the reassignment scenario (§7) clean:
at any moment there is exactly one current answer to "who teaches this", with a full history of
who held that answer before.

This is additive to widen later: introducing team teaching would mean relaxing the unique index
and adding a role column - a contained schema change that does not require revisiting anything
built here.

---

## 7. Reassignment: composing `end()` then `create()`, not a new operation

The brief lists four options for the reassignment scenario and asks for a reasoned choice: this
module rejects "update the existing assignment" (would silently rewrite history - Teacher A's
row would start claiming to be Teacher B's), "allow multiple simultaneous assignments" (rejected
in §6), and a bespoke "historical reassignment event" table (unnecessary: the existing
`ACTIVE → ENDED` transition plus an ordinary `create()` already **is** that event, expressed as
two rows rather than a new concept).

**Reassignment is therefore: `POST /teacher-assignments/{id}/end` the current assignment,
then `POST /teacher-assignments` for the new one.** Ending an assignment clears its
`active_marker` to `NULL`, which is precisely what frees the `(class_subject_id,
academic_session_id)` pair for the new row to claim - verified directly in
`TeacherAssignmentWorkflowTest` ("frees the class subject and session for a new assignment
once the old one ends") and end-to-end in `TeacherAssignmentManagementTest` ("supports
reassignment by ending the current assignment and creating a new one"). The old row is never
deleted or repointed; it remains readable forever with its own, correct `teaching_staff_id` and
`status: ENDED`.

No dedicated `/reassign` endpoint was built: it would be a thin wrapper over two calls that are
each already necessary and already independently permissioned (`teacher_assignments.end` and
`teacher_assignments.create` can be granted separately), and the brief explicitly cautions
against building a convenience endpoint before it is shown to be needed.

---

## 8. Class-subject validation (the multi-level guard)

Mirrors Module 06's enrollment validation and Module 07's own class-subject validation, for the
identical reason - and goes one level further than either, because it is validating a
*reference to* a class subject rather than the class subject's own creation:

- `class_subject_id` must exist and be `CatalogStatus::ACTIVE` - checked declaratively in the
  Form Request.
- Its **class** must independently be `ACTIVE`, and that class's **class level** must
  independently be `ACTIVE` - checked in `TeacherAssignmentService::assertClassSubjectSelectable()`
  against a loaded `ClassSubject::with('schoolClass.classLevel')`, because a class subject's own
  status is deliberately kept independent of its class's status (Module 07's design), so neither
  can be assumed from the other.

This is the same layering every prior module uses for a check that needs a loaded relation
rather than a plain column comparison: the Form Request owns what a single `exists()` can
express; the service owns what needs a join.

---

## 9. Permissions: `REGISTRAR` narrowed to view-only

`teacher_assignments.view/create/update/end/cancel` - five, seeded by
`TeacherAssignmentPermissionSeeder` with `syncWithoutDetaching()` after
`SubjectPermissionSeeder`.

`SUPER_ADMIN` and `ADMIN` hold all five. **`REGISTRAR` holds only `teacher_assignments.view`** -
a deliberate departure from Modules 05, 06 and 07's own pattern of granting `REGISTRAR` full
CRUD (minus delete) on their subject matter. The justification is direct, not assumed:
`RoleSeeder`'s own description of the role is *"Handles admissions, enrollment and student
records"* - three things, each of which Modules 05-07 gave `REGISTRAR` full rights over because
each is explicitly named as their job. **Teacher/staffing assignment is not named.** The closer
precedent is Module 03's own `staff.activate`/`staff.deactivate` split, withheld from
`REGISTRAR` because "ending or resuming an employment is a supervisory decision... not a
registrar's ordinary duties" - deciding who teaches what is the same kind of staffing decision.
A registrar still needs to **read** assignments (to answer "who teaches this class" while
building a timetable or a report), so `view` is granted; nothing else is.

`STAFF` and `STUDENT` hold none, matching every prior module.

---

## 10. Database design

| Column | Type | Constraint |
|---|---|---|
| `teaching_staff_id` | `foreignId` (→ `staff`) | `restrictOnDelete` |
| `class_subject_id` | `foreignId` (→ `class_subjects`) | `restrictOnDelete` |
| `academic_session_id` | `foreignId` (→ `academic_sessions`) | `restrictOnDelete` |
| `status` | `string(20)`, indexed | default `ACTIVE` |
| `active_marker` | `boolean`, nullable | derived from status only, never client-writable |
| `notes` | `text`, nullable | |
| `ended_at` | `timestamp`, nullable | |

**Uniqueness**: `unique(class_subject_id, academic_session_id, active_marker)` - the
scoped-singleton technique described in §1, verified directly (not merely asserted) by calling
`TeacherAssignmentService::create()` twice in a row from a test, bypassing the Form Request's
own check, and confirming the second call raises the same friendly `BusinessRuleViolation`
rather than a raw `QueryException`.

**Foreign-key deletion behaviour verified directly**: `TeacherAssignmentIntegrityTest` attempts
to delete a `ClassSubject` and a `Staff` record that a `TeacherAssignment` references and
confirms both raise a `QueryException` rather than cascading - no corruption is possible either
way, even though neither `class_subjects` nor `staff` has an HTTP delete path of its own that
would surface a friendlier error.

### 10.1 A known, accepted gap (documented, not fixed) - a fourth instance

`AcademicStructureService::deleteClass()`/`deleteSection()` and `SubjectService::deleteSubject()`
were each written before `teacher_assignments` existed and do not check for it. Per the
identical principle Module 05, 06 and 07's own audits already applied to the same class of gap:
this is not fixed here, because doing so means editing a completed module's service for a
cosmetic error-status improvement, and the underlying safety property (`restrictOnDelete`, no
corruption ever) already holds without it. `class_subjects` now has **two** undocumented
dependents (`enrollments`-adjacent concerns aside, specifically `teacher_assignments` here and
the gap Module 07's own audit already named); worth a single consolidated fix whenever these
services are next touched for another reason.

---

## 11. Security review performed

- **Authorization**: full matrix tested (guest, `STAFF`, `STUDENT`, `REGISTRAR`'s narrowed
  view-only grant specifically, suspended account, per-permission detachment for `create`).
- **Teacher eligibility**: a non-existent staff ID, a `NON_TEACHING` staff member, a
  `TERMINATED` staff member and an `INACTIVE` staff member are each refused and each asserted
  not to have created a row.
- **Mass assignment**: `TeacherAssignment::$fillable` lists only `notes`; the three reference
  fields and `status` have no key in *any* request, verified by injecting all four (plus
  `active_marker`) into every write payload and asserting none of them land.
- **IDOR**: verified that a route parameter naming one assignment's id cannot be used to amend
  a *different* one.
- **Academic integrity**: a class subject whose class is retired, whose class level is retired,
  a `COMPLETED` session, and a duplicate `(class_subject, session)` active pair are each refused
  and each asserted not to have created a row.
- **Concurrency**: the unique-index race is exercised directly (bypassing the Form Request's
  own check) and confirmed to surface as the same `BusinessRuleViolation` message, not a raw
  `500`.
- **Foreign-key deletion behaviour**: verified directly rather than assumed - see §10.

---

## 12. What this module owes the next one

- `teacher_assignments.id` is the anchor a future assessment/score/result chain should
  reference when it needs to know who taught a class subject in a given session - not
  `class_subjects.id` directly, and not `staff.id` directly.
- A future "my teaching load" self-service endpoint for `STAFF` is a real, deferred need - see
  §9 - additive when built, not a redesign of this module's permission grants.
- A future Class/Form Teacher module (pastoral responsibility for a class or section, distinct
  from subject teaching) is explicitly out of scope here and should not be retrofitted onto
  this table via a `teacher_type` column - the brief is explicit that the two are different
  concepts and a generic type field would conflate them.
- The Module 02/07 delete-guard gap (§10.1) is real but non-corrupting - now spanning
  `class_subjects` and, transitively, `staff` - worth a single consolidated fix whenever those
  services are next touched.

---

## 13. Verification

See the final report delivered alongside this audit for the test run, `Pint`, and
migration/seed results.
