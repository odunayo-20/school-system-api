# Module 09 — Assessment Configuration: Architecture Audit

Assessment Types (a reusable category catalogue) and Assessments (an actual configured
assessment tied to a class subject and a term) — deliberately **without** Assessment Scores,
Grading, or Result Compilation, which are reserved for Modules 10-14.

## 1. What was already in the codebase

A repo-wide, case-insensitive search for "assessment" across `app`, `database`, `routes` and
`tests` (excluding `vendor`) returned **zero existing assessment code** — only forward-looking
comments already written in Modules 07 and 08, anticipating this module:

- `ClassSubject.php`: "a future assessment will reference [this row]… a teacher and an
  assessment both belong to 'Mathematics as taught in JSS 2'".
- `TeacherAssignmentController.php`: "a future assessment, score and result chain will
  reference [this row]".
- The `subjects`, `class_subjects` and `teacher_assignments` migrations each state explicitly
  that they hold no `assessment_id` or score column.

This confirms Module 09 is fully greenfield: no naming conflicts, no partial implementation to
reconcile.

Two existing pieces were the ones this module's design turns on:

- **`Term.academic_session_id`** is a real, first-class FK column (`Term belongsTo
  AcademicSession`), not merely reachable through a join. An `Assessment.term_id` reference can
  therefore derive the academic session via `term.academicSession` without Assessment needing
  its own `academic_session_id` column.
- **`TermStatus`** already exposes `isCompleted()`, the identical shape `AcademicSessionStatus`
  uses — directly reusable for the "term must not be COMPLETED" gate every module since
  Admission applies to its own session/term reference.

## 2. Files

### 2.1 Created

- `database/migrations/2026_10_03_090000_create_assessment_types_table.php`
- `database/migrations/2026_10_03_090001_create_assessments_table.php`
- `app/Models/AssessmentType.php`, `app/Models/Assessment.php`
- `app/Services/Assessment/AssessmentService.php`
- `app/Http/Requests/Assessment/{Store,Update}AssessmentTypeRequest.php`,
  `AssessmentTypeListRequest.php`
- `app/Http/Requests/Assessment/{Store,Update}AssessmentRequest.php`,
  `AssessmentListRequest.php`
- `app/Http/Requests/Assessment/Concerns/ValidatesAssessmentRecord.php`
- `app/Http/Resources/AssessmentTypeResource.php`, `app/Http/Resources/AssessmentResource.php`
- `app/Http/Controllers/Api/V1/Assessment/AssessmentTypeController.php`,
  `AssessmentController.php`
- `database/seeders/AssessmentPermissionSeeder.php`
- `database/factories/AssessmentTypeFactory.php`, `database/factories/AssessmentFactory.php`
- `tests/Feature/Assessment/{AssessmentTypeManagementTest,AssessmentManagementTest,
  AssessmentTypeSecurityAndFilterTest,AssessmentSecurityAndFilterTest,
  AssessmentIntegrityTest}.php`
- `docs/api/assessment-configuration.md`, this audit

### 2.2 Modified

- `routes/api.php` — new `assessment-types` and `assessments` route groups.
- `database/seeders/DatabaseSeeder.php` — `AssessmentPermissionSeeder::class` added after
  `TeacherAssignmentPermissionSeeder::class`.
- `tests/TestCase.php` — same seeder added to the baseline `$this->seed([...])` list.
- `tests/Pest.php` — imports, `assessmentTypeCreatePayload()`, `assessmentTypeUpdatePayload()`,
  `catalogAssessmentType()`, `eligibleTerm()`, `assessmentCreatePayload()`,
  `assessmentUpdatePayload()`, `activeAssessment()`, `retiredAssessment()`.

### 2.3 Kept unchanged

Every file from Modules 01-08. `ClassSubject`, `TeacherAssignmentService`, `Term`,
`ValidatesCatalogRecord` and `SubjectService` were **read fresh in this session** before reuse,
not assumed from memory, per this project's own audit discipline — see §1 and §4.

### 2.4 Deliberately not created

- **No `AssessmentTypeStatus` enum.** `CatalogStatus` is reused verbatim for both entities — see
  §5.
- **No `AssessmentScore`, `Grade`, or `Result` model, table, or column.** Explicitly out of
  scope per the module brief; reserved for Modules 10-14.
- **No `DELETE /assessments/{id}`.** See §7.
- **No `Repository`, `DTO`, `Interface`, `Manager`, `Helper`, `Transformer`, `Observer`, or
  `Events/Listeners`.** Nothing in this module's shape needs one; the existing
  Model → FormRequest → Service → Controller → Resource pipeline handles it entirely, matching
  every module before it.
- **No teacher-scoped ("my assigned class subjects only") authorization.** See §9.
- **No cross-assessment "weights must total 100%" validation.** See §6.

## 3. The central decision: how an assessment names its academic context

The brief's own Question A asked whether an assessment should reference Class Subject + Term,
Session + Term + Class Subject, or something else. Resolved by inspecting `Term` directly
(§1): **`class_subject_id` + `term_id`**, with the academic session derived through
`term.academicSession` rather than stored.

This mirrors a decision Module 08 already made for a different pair: `TeacherAssignment`
references `class_subject_id` rather than separate `school_class_id`/`subject_id`, because
`ClassSubject` already names both at once and duplicating them would let the two drift. The
identical reasoning applies here one layer further: `Term` already names its session, so
storing `academic_session_id` on `Assessment` too would be the same duplication, just moved up
a level.

**Question B** (whether `class_subject_id` avoids duplicating class/subject identity) is
answered by the same fact: yes, `ClassSubject` already carries both.

## 4. Class-subject validation (the multi-level guard, extended a third time)

`ClassSubject.status` is deliberately **independent** of its parent `SchoolClass.status` and
that class's `ClassLevel.status` — Module 07's own design (a class subject can remain nominally
`ACTIVE` after its class is archived). Every module that references a class subject since has
therefore needed its own three-level check against loaded relations, because none of the three
statuses can be assumed from another:

- Module 06 (`EnrollmentService::assertClassLevelActive()`)
- Module 07 (`SubjectService::assertClassSelectable()`, two levels — no class subject yet)
- Module 08 (`TeacherAssignmentService::assertClassSubjectSelectable()`, three levels)
- Module 09 (`AssessmentService::assertClassSubjectSelectable()`, three levels — the exact
  method Module 08 already wrote, reused as a design pattern rather than copy-pasted code,
  since the two services do not share a parent class and duplicating three lines of logic
  across two services is smaller and clearer than extracting a shared trait for one guard used
  by two callers)

This lives in the service layer, not a declarative Form Request rule, because it needs
`ClassSubject::with('schoolClass.classLevel')` loaded — a join a `Rule::exists()` closure
cannot express.

## 5. Assessment Type: a twin of Subject, not a new lifecycle

**Question F** asked whether Assessment Type should be a database catalogue or an enum.
Resolved as a catalogue table, structurally identical to `Subject`:

- `ValidatesCatalogRecord` is reused **verbatim** (the same trait Subject's own Store/Update
  requests already reuse cross-namespace from Module 02) — a globally unique name and code, an
  optional `sort_order`, a `CatalogStatus`.
- A guarded `DELETE` endpoint, mirroring `SubjectService::deleteSubject()`: refused while any
  `Assessment` references the type, checking **any** row regardless of status (an `INACTIVE`
  assessment is still a historical fact that once used the category).

An enum was rejected because a school's assessment categories are data a registrar configures
(a school might add "Project" or "Oral" as its own category), not a fixed set the application
hardcodes — the identical reasoning `ClassLevel`'s own docblock gives for why its four stages
are rows, not cases.

## 6. `max_score` vs `weight`, and the rule this module deliberately does not add

**Question C** distinguished the two: `max_score` is the ceiling a score against this
assessment may not exceed (required, must be `> 0`); `weight` is this assessment's optional
share of the class subject's overall grade for the term, as a percentage (`0`–`100`).

The brief explicitly cautioned against reflexively inventing a "weights must total 100%"
cross-assessment rule. That caution was followed: **no such rule exists anywhere in this
module.** Two assessments in the same class subject and term may carry weights that sum to
anything, including nothing at all (both `null`) or more than 100 —
`AssessmentManagementTest::'it does not require the weights of several assessments in the
same class subject and term to total 100'` pins this directly. The reasoning: weighting policy
(whether it must total 100%, whether some assessments carry no weight) is a property of a
*grading scheme*, which does not exist yet — Module 11+ owns that decision, and this module
cannot enforce a rule it does not yet know the shape of without guessing at architecture no
requirement has specified.

## 7. Deletion policy: Assessment Type is catalogue-family, Assessment is anchor-family

Continuing the two-shape delete convention established across every prior module:

- **`AssessmentType`** — catalogue-family, like `ClassLevel`/`SchoolClass`/`Section`/`Subject`.
  Gets a guarded `DELETE`.
- **`Assessment`** — anchor-family, like `ClassSubject`/`Enrollment`/`Admission`/
  `TeacherAssignment`. Gets **no delete endpoint at all**: Module 10 (Scores) will reference
  `assessment_id`, so deleting one silently would either cascade-destroy future score history or
  require deciding what happens to scores against a row that no longer exists — a decision this
  module has no way to make correctly. `status: INACTIVE`/`ARCHIVED` through the ordinary `PUT`
  replaces it, the identical posture `ClassSubject` already takes.

## 8. Lifecycle: a reversible toggle, not a terminal workflow

**Question E** (ordering/sequence) and the general lifecycle question were resolved together:
`sort_order` (nullable int, matching the established catalog naming convention) supports
reordering, and both `AssessmentType` and `Assessment` reuse `CatalogStatus` as a **freely
reversible** toggle set through the ordinary `PUT` — not a one-shot terminal lifecycle like
`Admission`'s `PENDING → ADMITTED|REJECTED|WITHDRAWN` or `TeacherAssignment`'s
`ACTIVE → ENDED|CANCELLED`.

This was a deliberate choice between two established shapes this project already has:

| Shape | Used for | Reachable via |
|---|---|---|
| Freely reversible `CatalogStatus` | "Is this currently offered/in use" facts | Ordinary `PUT` |
| One-shot terminal lifecycle | "A decision/placement/responsibility permanently ended" facts | Dedicated workflow endpoint only |

"This assessment is no longer offered" is conceptually identical to `ClassSubject.status`'s
"is this currently offered" — not a person, placement, or responsibility ending. A dedicated
`end`/`cancel`-style endpoint would add nothing a plain amend does not already give.

## 9. Authorization boundary: teacher-scoped access is deferred, not built

The brief's Section 14 asked explicitly to reason through whether only admins/registrars
configure assessments, or whether assigned teaching staff can too. Resolved as: **`STAFF`
(including `TEACHING`) holds nothing in this module**, for a reason grounded in what the
codebase can actually express, not preference:

- Every permission gate in Modules 01-08 is a **flat** `permission:x.y` check — a holder of
  `assessments.create` can create an assessment for *any* class subject, full stop. There is no
  ownership-scoped variant ("only for class subjects I am assigned to teach") anywhere in this
  project; building one would mean introducing a Policy or an ownership check that does not
  exist yet — precisely the kind of "speculative future architecture" this project's own
  conventions (and this module's brief, Section 2) caution against building ahead of a genuine
  requirement.
- The module that will **actually need** ownership-scoped authorization for the first time is
  the future assessment *scores* module: a teacher entering scores plausibly should be
  restricted to the class subjects they are assigned to (Module 08's `TeacherAssignment` is
  exactly the fact that would answer "is this teacher assigned to this class subject"). Scoping
  is deferred to that module, where it has a genuine consumer, rather than invented here for a
  read-only convenience this module's own brief does not require.
- `REGISTRAR`, by contrast, gets full CRUD minus delete on both entities: `RoleSeeder` names
  "admissions, enrollment and student records" among a registrar's duties, and assessment
  configuration is the same kind of curriculum-structure work Module 07 already gave `REGISTRAR`
  full CRUD over (`subjects.*`, `class_subjects.*`) — **not** the staffing-level decision Module
  08 narrows `REGISTRAR` to view-only for. This is the same heuristic applied consistently since
  Module 05: if `RoleSeeder`'s stated duties name the domain, `REGISTRAR` gets full CRUD; if the
  domain is a supervisory/staffing decision, `REGISTRAR` is narrowed.

## 10. Database design

- `assessment_types`: `name` (unique, max 100), `code` (unique, max 20), `sort_order`
  (`smallInteger`, default 0), `status` (string 20, default `ACTIVE`) — column-for-column
  identical shape to `subjects`.
- `assessments`: `class_subject_id`/`term_id`/`assessment_type_id` (all `restrictOnDelete`),
  `name` (max 100), `max_score` (`decimal(6,2)`), `weight` (`decimal(5,2)`, nullable),
  `sort_order`, `status`.
- **`unique(class_subject_id, term_id, name)`** — the database-level half of "CA1 and CA2 may
  coexist, but the same name cannot be configured twice for the same class subject and term".
  The form request enforces the same rule with a friendly 422 first (`Rule::unique(...)->where(...)`
  scoped to the request's own `class_subject_id`/`term_id` on Store, or the existing record's
  immutable ones on Update); the index is the race-safe backstop, caught in
  `AssessmentService::create()`/`update()` via the same SQLSTATE `23000` pattern every module
  since Enrollment uses.
- All three foreign keys `restrictOnDelete`, matching every foreign key introduced since Module
  05: an assessment is exactly the kind of academic-history anchor a future score will
  reference.
- A `CHECK (max_score > 0)` constraint was considered and skipped: SQLite (the test driver)
  enforces `CHECK` inconsistently across `ALTER` paths, and every other numeric boundary already
  validated in this project (`term_number`, `sort_order`) relies on the application layer alone
  — consistent with existing precedent rather than a new, driver-fragile guarantee.

## 11. Security review performed

- **Authentication**: every endpoint behind `auth:api` + `active` — verified by
  `*SecurityAndFilterTest`'s unauthenticated-caller cases.
- **Authorization**: per-action permission gates, verified with detach-one-permission tests
  (create revoked, view/update still work) and full role-matrix tests (`STAFF`/`STUDENT`
  forbidden, `REGISTRAR` full CRUD minus delete, `ADMIN`/`SUPER_ADMIN` full CRUD).
- **Mass assignment**: `class_subject_id`/`term_id`/`assessment_type_id`/`status` on create,
  and the three identity fields on update, are absent from `$fillable` and set only via
  `forceFill()` inside the service — verified by dedicated injection-attempt tests.
- **IDOR**: verified one assessment cannot be read or amended through another's ID.
- **Input validation**: every FK reference validated against the database (existence + status),
  never trusted from the client; `max_score`/`weight`/`sort_order` bounds enforced server-side
  regardless of what a client sends.
- **Mixed-precision inputs**: `max_score`/`weight` accept both integer and decimal JSON values
  (`20` and `20.00` both valid) since Laravel's `numeric` rule does not distinguish, matching
  how a client is likely to send either.

## 12. What this module owes the next one

Module 10 (Assessment Scores) can safely assume:

- Every `Assessment` row names a class subject, term and category that were all `ACTIVE`/valid
  at creation time, and none of the three can have silently changed since — they are immutable.
- An assessment's `status` may be `INACTIVE`/`ARCHIVED` at any time; Module 10 must decide for
  itself whether a score can be recorded against a non-`ACTIVE` assessment (this module takes no
  position on that).
- `max_score` is always `> 0`; a score compiler never needs to guard against a zero denominator
  from this table.
- No teacher-scoped read/write access exists yet on `assessments.*` — Module 10 is where that
  first needs to be built, using `TeacherAssignment` as the source of truth for "is this teacher
  assigned to this class subject".

## 13. Verification

- `php artisan test`: **794 passed** (696 pre-existing + 98 new), zero failures, zero skipped.
- `./vendor/bin/pint --test`: clean.
- `php artisan migrate:fresh --seed`: clean run, no errors.
- Live HTTP smoke test (`php artisan serve`, a minted Sanctum token for the seeded super admin):
  created an assessment type, a class/subject/class-subject, configured CA1 and CA2 against the
  same class subject and term, confirmed a third `CA 1` duplicate is rejected with `422`,
  confirmed `max_score: 0` is rejected, amended CA1's name/score while confirming
  `class_subject_id` in the same payload is silently ignored, confirmed `DELETE
  /assessments/{id}` answers `405`, confirmed `DELETE /assessment-types/{id}` in use answers
  `422`, and confirmed `class_subject_id` filtering on the list endpoint returns exactly the
  expected rows.
- `sort_order` returning `null` rather than `0` on a create that omits it was investigated and
  confirmed to be **pre-existing behaviour inherited unchanged from `Subject`** (verified
  directly via `Subject::create()` in `tinker`), not a Module 09 regression.
