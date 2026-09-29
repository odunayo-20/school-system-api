# Module 12 — Result Compilation: Architecture Audit

Transforming raw assessment scores into a compiled Subject Result - one enrollment, one class
subject, one term. Explicitly without Result Approval, Result Publication, Report Cards,
Promotion, a Result Checker, Attendance, Timetable, or Notifications, all reserved for later
modules.

## 1. What was already in the codebase

No result/compilation code existed anywhere. The audit focused on five things this module's
design turns on:

- **`Assessment`** (Module 09): `class_subject_id`, `term_id`, `max_score` (`decimal(6,2)`),
  `weight` (nullable `decimal(5,2)`, NOT constrained to sum to 100 across a class subject's
  assessments - a deliberate Module 09 design already confirmed by the Module 11 audit),
  `status` (`CatalogStatus`).
- **`Score`** (Module 10): `assessment_id`, `enrollment_id` (not `student_id` - the exact
  precedent this module's own `results.enrollment_id` follows), `score` (`decimal(6,2)`), with
  `max_score` deliberately never duplicated onto the table.
- **`GradingService::calculate(GradingScale $scale, float $percentage)`** (Module 11): the sole
  percentage-to-grade primitive, returning `null` when no band covers the percentage. It reads
  no `Score`/`Assessment` at all and takes a plain float - confirming it is safe to call from a
  second module without any change. **One method was added** here,
  `findActiveForClassLevel(int $classLevelId): ?GradingScale`, because every existing caller
  (`GradingScaleController`) already had a specific scale id in hand from its own route
  parameter; this module is the first that needs to go from "a class level" to "the scale that
  applies," and Module 11's own `unique(class_level_id, active_marker)` index (see the Module 11
  audit §3-4) is exactly what makes that lookup unambiguous - at most one row can match.
- **`ScoreService::isAssignedTeacher()`/`activeTeachingAssignments()`** (Module 10): the
  established teacher-assignment scoping shape, keyed on the
  `(class_subject_id, academic_session_id)` PAIR, never `class_subject_id` alone (a real bug
  Module 10 itself caught and fixed - `TeacherAssignment` is session-scoped). Re-implemented
  here parameterized by raw ids rather than an `Assessment`, the same "small enough to
  duplicate, not worth a shared trait" precedent every prior module's own copy of this check
  already follows.
- **`Enrollment`** (Module 06): the authoritative academic placement - `school_class_id`,
  `section_id`, `academic_session_id`, `status`. Confirmed (again) as the correct anchor for a
  result, not a mutable `current_class_id`, which the student table still does not have.

## 2. Files

### 2.1 Created

- `database/migrations/2026_10_06_090000_create_results_table.php`
- `app/Models/Result.php`, `app/Enums/ResultStatus.php`
- `app/Services/Result/ResultService.php`
- `app/Http/Requests/Result/{CompileResultRequest,CompileResultBulkRequest,ResultListRequest}.php`
- `app/Http/Resources/ResultResource.php`
- `app/Http/Controllers/Api/V1/Result/ResultController.php`
- `database/seeders/ResultPermissionSeeder.php`
- `database/factories/ResultFactory.php`
- `tests/Feature/Result/{ResultCompilationTest,ResultSecurityAndFilterTest,
  ResultBulkCompileTest,ResultIntegrityTest}.php`
- `docs/api/result-compilation.md`, this audit

### 2.2 Modified

- `app/Services/Grading/GradingService.php` - added `findActiveForClassLevel()` (see §1). No
  other change to an existing Module 11 file.
- `routes/api.php` - new `results` route group: `GET /results`, `GET /results/{id}`,
  `POST /results/compile`, `POST /results/bulk`.
- `database/seeders/DatabaseSeeder.php` - `ResultPermissionSeeder::class` added after
  `GradingPermissionSeeder::class`.
- `tests/TestCase.php` - same seeder added to the baseline `$this->seed([...])` list (now
  twelve seeders).
- `tests/Pest.php` - imports, `resultCompilationContext()`, `compileResultPayload()`,
  `compiledResult()`.

### 2.3 Kept unchanged

Every file from Modules 01-11. `ScoreService`'s teacher-scoping shape, `GradingService`'s
`calculate()`, `ApiResponse`, `ListRequest`, `BusinessRuleViolation` and
`AuthorizationException`'s existing 422/403 rendering were all read fresh in this session
before reuse, not assumed from memory.

### 2.4 Deliberately not created

- **No `ResultRepository`, `ResultManager`, `ResultCompilerInterface`,
  `ResultCalculationDTO`, `ResultTransformer`, `ResultHelper`, `ResultObserver`, no
  Events/Listeners.** The calculation is two private methods on `ResultService`
  (`calculateOutcome()`, `calculatePercentage()`) totaling under 90 lines with their own
  docblocks; nothing about this module's actual size or the number of current consumers (one -
  the controller) justifies any of these.
- **No `ResultItem` child table, no parent/child Result structure.** See §3.
- **No raw-total or per-assessment-breakdown column on `results`.** See §4.
- **No `ResultApprovalService`, no publication workflow, no report-card rendering, no
  promotion logic, no `/result-checker` student endpoint, no Attendance/Timetable/Notification
  code of any kind.** All explicitly out of scope per the brief's own boundary (§0); the only
  hook left for them is `ResultStatus::LOCKED` (§7).
- **No new authorization abstraction.** `AuthorizationException` (already rendered as 403
  since Module 01/02's `bootstrap/app.php` wiring) is thrown directly from
  `assertTeacherAuthorized()`, identical to `ScoreService`'s own pattern - zero new plumbing.
- **No BCMath or arbitrary-precision arithmetic.** Plain PHP floats, rounded exactly once at
  the final percentage - matching `ScoreResource::scorePercentage()`'s own established
  technique, not a new numeric strategy this codebase does not otherwise use.
- **No duplicate `Assessment`, `Score`, `Enrollment`, `ClassSubject`, `Term`, or `GradingScale`
  model or logic.** `ResultService` reads all of them directly via their own established
  relations and query patterns; it introduces no second implementation of any of them.

## 3. The central decision: one table, not Result + ResultItem

**The brief's own §2-4** asked whether the result aggregate needs a parent/child shape. The
domain flow this module's own brief describes stops at "Subject Result → Grade" - there is no
cross-subject aggregate (an overall term average, a class position, a report card) anywhere in
this module's scope. A parent `Result` row (one per enrollment+term) with child `ResultItem`
rows (one per subject) would be the correct shape **the moment** a cross-subject aggregate is
introduced - but building that shape now, with no second concept for the parent envelope to
hold, would be exactly the "speculative architecture ahead of a proven need" this project's own
house rules forbid throughout. **Resolution:** one table, `results`, keyed
`(enrollment_id, class_subject_id, term_id)`. Should a future module need a term-level
aggregate, it can be added as its own table referencing a SET of `results` rows, without
requiring this table's own shape to change - the two are cleanly separable, not the same
concern wearing two names.

## 4. Why no raw total or score breakdown is stored

**The brief's own §7-8** raised the question of what a compiled result should hold beyond the
final percentage/grade. A raw sum of differently-scaled, differently-weighted assessments (a
20-mark CA added to a 100-mark exam, or a weighted contribution already collapsed into one
number) has no honest standalone meaning once weighting is in play - storing one would be
exactly the "ambiguous total field" a careful design avoids. `Score` (Module 10) remains the
SOLE authoritative raw input; nothing about it is snapshotted or duplicated here. A client
wanting the breakdown behind a result calls `GET /scores?enrollment_id=...&subject_id=...&
term_id=...`, the same filters Module 10 already exposes, rather than this module maintaining a
second, potentially-diverging copy of the same facts.

## 5. Uniqueness and authority: enrollment, not student or `current_class_id`

**The brief's own §5-6, and the identical question every prior module already answered**: a
result belongs to the student's authoritative PLACEMENT for the session the term falls in,
never merely to the person. `unique(enrollment_id, class_subject_id, term_id)` is therefore the
one row in this project needing all three coordinates at once, for reasons independently
confirmed for each:

- `class_subject_id` is a standing curriculum fact with no session or term of its own (Module
  07's own design) - it cannot stand in for `term_id`.
- `enrollment_id` is scoped to a whole academic SESSION, not to any one of its terms (Module
  06's own design - a placement spans every term of the session it names) - it cannot stand in
  for `term_id` either.
- `student_id`/`current_class_id` never entered the design at all - `Student` still carries no
  such column, exactly as every enrollment-consuming module since Module 06 has confirmed.

## 6. The one calculation path

`ResultService::calculateOutcome()` and its private helper `calculatePercentage()` are the
SOLE place a percentage, completeness flag, or grade is derived anywhere in this codebase for
this module. The controller calls `compile()`/`compileClassSubject()`; nothing else reads a
`Score` row for this purpose.

### 6.1 Weighting - consuming Module 09 without modifying it

**The brief's own §11-12's central question**: how to combine several assessments' scores
without double-counting or trusting a client total. Resolved as two branches:

- **Weighted**: if any SCORED assessment carries a configured `weight`, the percentage is
  `Σ (score / max_score * weight)` over scored-and-weighted assessments only. A scored-but-
  UNWEIGHTED assessment is excluded from the sum entirely - Module 09's own design already
  treats `weight: null` as "not part of the configured weighting scheme," so it contributes
  nothing here either, rather than being silently folded into either branch.
- **Unweighted fallback**: if no scored assessment carries a weight at all, the percentage is a
  plain ratio - `Σ(scored marks) / Σ(every ACTIVE assessment's max_score) * 100`. The
  denominator DELIBERATELY includes unscored assessments' `max_score`, not only the scored
  subset's.

**Neither branch renormalizes.** A missing assessment's weight (or `max_score`, in the
unweighted denominator) is simply never recovered by the assessments that WERE scored - this is
the one deliberate, explicitly-documented answer to "what happens when scores are missing"
(§8 below), applied uniformly whether one assessment or all-but-one are still unscored. The
alternative (averaging only over what's been scored so far) was rejected because it would let a
single high mark on one assessment produce a misleadingly high "percentage so far" - exactly
the kind of silently-inflated partial result a careful design avoids.

Rounding happens exactly once, on the final percentage - never on an intermediate ratio or
running sum - matching `ScoreResource::scorePercentage()`'s own established technique.

### 6.2 Completeness and the missing-scores policy

**The brief's own §9-10, its most explicit instruction: "never silently treat a missing score
as zero."** `calculateOutcome()` computes `$isComplete` as "every ACTIVE assessment for this
class subject and term has a score on file for this enrollment." When incomplete:

- The percentage IS still computed (§6.1's honest-capping guarantee makes this safe - it can
  never read as more complete than it is).
- `status` is `INCOMPLETE`, and `grade`/`grade_point`/`remark` are all `null` - grading a
  partial picture would misrepresent it as final, so grading is withheld entirely rather than
  guessed or interpolated.

This is the one considered, documented answer this module makes to the missing-scores
question: **compute an honest partial percentage; withhold the grade.** Both "block compilation
entirely until every score is in" and "treat a missing score as zero" were considered and
rejected - the former makes the `GET /results` list useless as a live in-progress view for a
teacher mid-term; the latter actively lies about the student's standing.

### 6.3 Grading integration - no hardcoded rules

`ResultService` never compares a percentage against a literal number. When `$isComplete`, it
resolves `classLevelId` from `$classSubject->schoolClass->classLevel->id`, calls
`GradingService::findActiveForClassLevel()` then `GradingService::calculate()` (§1), and copies
`grade`/`grade_point`/`remark` through unchanged. A missing scale, or a percentage no band
covers, both resolve to `null` - the identical "no match is not a failure" posture Module 11
already established, never re-implemented here.

## 7. Snapshot vs. live calculation, and the `LOCKED` hook for Module 13

**The brief's own §15-16's central question**: should a compiled result be a live view or a
frozen snapshot? **Resolution: a snapshot, taken at compile time, that only this module's own
compile operation ever overwrites.** `results.percentage`/`grade`/`grade_point`/`remark` are
persisted columns, not values computed at read time from `Score`/`Assessment`/`GradingScale` on
every `GET` - a compiled result reflects the state of scores AS OF the last compile, exactly
until someone calls compile again. This is deliberately the smaller of two designs: a
"live-calculated" result (recomputed on every read) would need no `results` table at all, but
would also give Module 13 (Approval/Publication) nothing stable to approve - an "approved"
result that silently changes underneath its own approval the next time a score is corrected
would defeat the entire point of an approval step. A persisted snapshot, recomputed only on an
explicit recompile, is the minimum structure a future lock/approval system needs, and is
exactly what `ResultStatus::LOCKED` is reserved for:

- `ResultStatus` is a minimal three-state enum - `INCOMPLETE`, `COMPILED`, `LOCKED` -
  **deliberately not** the brief's own suggested five-state
  `DRAFT`/`SUBMITTED`/`APPROVED`/`PUBLISHED`/`LOCKED` shape. Every existing status enum in this
  project (`EnrollmentStatus`, `AdmissionStatus`, `TeacherAssignmentStatus`, `CatalogStatus`)
  was checked; none has that shape, so it is NOT an "established project workflow" this module
  is obligated to extend (the brief's own conditional phrasing) - it is the brief's own
  speculative suggestion for what MODULE 13 might look like, out of place inside Module 13's own
  scope until Module 13 actually needs it.
- `LOCKED` is produced by NOTHING in this module - there is no endpoint that sets it. It exists
  purely so `persist()` can check `$result->exists && $result->isLocked()` and refuse a
  recompile with `422` BEFORE a future locking mechanism is built, so that mechanism needs no
  change to `ResultService` itself to become enforceable the moment it exists.

## 8. Idempotent recompilation and concurrency

**The brief's own §17-18's central question**: recompiling after a score correction must
update the SAME row, never create a second one, under concurrent access. `persist()` is a
single method both `compile()` and `compileClassSubject()` route through:

1. `calculateOutcome()` runs OUTSIDE the transaction (pure computation, no lock needed).
2. Inside `DB::transaction()`: `Result::query()->where(triple)->lockForUpdate()->first() ??
   new Result` - finds the existing row (locked against a concurrent second recompile) or
   prepares a new one.
3. If found AND locked, refuse (§7).
4. `forceFill()` the triple plus every calculated field, `save()`.
5. A `QueryException` on the unique index (`SQLSTATE 23000`) is caught and converted to a
   friendly `BusinessRuleViolation` - the narrower race window for two truly CONCURRENT
   first-time compiles of the same triple, where neither transaction has a row to lock yet.

This is the identical two-layer concurrency guarantee (row lock for the common case, unique
index as the last-resort backstop) every prior module's own `create()` already uses for its own
unique constraint, extended here to an UPSERT rather than a pure insert -
`ResultIntegrityTest::'is idempotent: recompiling with unchanged scores keeps exactly one row
for the triple'` and `'serializes two concurrent recompiles...'` both pin this directly, and
`ResultCompilationTest::'recompiles the same row with an updated percentage and grade after a
score is corrected'` pins the end-to-end score-correction workflow the brief names explicitly.

## 9. API design: single and bulk, deliberately not all-or-nothing

**The brief's own §14 and its bulk-operation questions.** `POST /results/compile` takes exactly
the identifying triple (never a calculated value - see §11's mass-assignment note) and answers
`200` (not `201` - see the API doc §1.2). `POST /results/bulk` takes `class_subject_id` +
`term_id`, validates and authorizes that shared context ONCE, then compiles every currently
`ACTIVE` enrollment in the class subject's own class and the term's own session,
**independently**: each student's outcome (`result`/`error`) is reported separately, and one
student's entirely legitimate `INCOMPLETE` outcome, or any other per-student `BusinessRuleViolation`,
never aborts the rest of the class. This is a DELIBERATE departure from Module 10's own bulk
score entry (`ScoreService::createBulk()`, all-or-nothing under one transaction) - the
difference is not stylistic. Module 10's bulk write is entering NEW client-supplied data, where
one bad row plausibly indicates a client-side mistake worth rejecting wholesale before any of
it lands. Module 12's bulk compile is re-deriving already-valid, already-persisted history for
students independently of each other; there is no shared "this whole batch might be wrong"
risk a single student's incompleteness could signal about their classmates.

## 10. Authorization

**The brief's own §19-22 questions, answered:**

- **Base permissions**: `results.view`, `results.compile` only - no `.create`/`.update`/
  `.delete` (a result is written only through `compile()`, never a raw create or amend) and no
  separate `.bulk` permission (compiling a class subject in one call is the same capability as
  compiling one student's, at the shape a class teacher actually works in).
- **Teacher scope, the module's central authorization requirement**: `assertTeacherAuthorized()`
  requires an `ACTIVE` `TeacherAssignment` for the EXACT `(class_subject_id, academic_session_id)`
  pair, reusing Module 10's own established shape unchanged in substance. A Mathematics teacher
  cannot compile English results; a teacher assigned to one class/session cannot compile
  another, even a prior session's assignment to the identical class subject -
  `ResultSecurityAndFilterTest` pins every one of these by name.
- **List scoping applied UNCONDITIONALLY, before any filter** - `query()` restricts a `STAFF`
  caller's results to their own active assignment pairs BEFORE `?class_subject_id=`/etc. is
  ever applied, so a filter naming data outside a teacher's own scope returns an empty list,
  never someone else's data (`'a teacher cannot bypass their own scope simply by naming another
  class subject's result in a filter'`).
- **`REGISTRAR` holds FULL access** (`view` + `compile`) - a deliberate departure from Module
  08's `teacher_assignments.*` and Module 11's `grading_scales.*`, both of which narrow
  `REGISTRAR` to view-only as staffing/policy decisions outside `RoleSeeder`'s own stated
  duties. Compiling a result is MECHANICAL EXECUTION of an already-configured process
  (assessments, weights and grading scales, all configured by others in earlier modules) over
  student records - the same "admissions, enrollment and student records" territory
  `RoleSeeder` already names for `REGISTRAR`, and the identical bucket Module 09's own
  curriculum-structure permissions fall into, not a policy decision like Module 11's grade
  boundaries.
- **Student access**: explicitly NONE in this module (`Role::STUDENT->value => []`). Raw
  compiled results, ahead of any approval or publication, are working data for teachers and
  administrators; a future Result Checker module is where student-facing access belongs, per
  the brief's own explicit boundary (§0, §22).

## 11. Security review performed

- **Authorization bypass**: `ResultSecurityAndFilterTest` verifies `STAFF` without an
  assignment, an unassigned teacher, a teacher whose assignment has ended, a teacher assigned
  to a DIFFERENT session, a non-teaching staff member, and `STUDENT` are all refused compile
  and/or view access, per role.
- **IDOR**: `'refuses an unassigned teacher reading a result by id (IDOR)'` - a result cannot be
  read through another teacher's own id-guessing.
- **Filter-injection scope bypass**: `'a teacher cannot bypass their own scope simply by naming
  another class subject's result in a filter'` - confirms the scope is applied before, not
  instead of, a client filter.
- **Mass assignment**: `'cannot bypass the server-calculated percentage, grade or grade point by
  sending them in the compile payload'` - sends `percentage: 100`, `grade: 'A'`, `grade_point:
  5`, `status: 'COMPILED'` alongside a valid triple whose real answer is `50.00`, and confirms
  the server-derived value wins every time; `Result::$fillable` excludes `enrollment_id`/
  `class_subject_id`/`term_id` entirely, requiring `forceFill()` at the one call site
  (`persist()`) that is allowed to set them.
- **Locked-result protection**: `'refuses to recompile a locked result'` at both the HTTP layer
  and directly against `ResultService::compile()` - confirms the guard exists at the service
  layer, not only behind a controller-level check that could be bypassed by a future direct
  service caller.
- **No delete/update surface**: `POST`/`PUT`/`PATCH`/`DELETE` against `/results/{id}` are all
  confirmed `405`.
- **Historical corruption**: every foreign key is `restrictOnDelete` - an enrollment, class
  subject or term referenced by any result cannot be deleted out from under it.

## 12. QA review

- **Focused tests**: 72 new tests across `ResultCompilationTest` (single compile, weighting -
  weighted/unweighted/mixed, missing-scores/INCOMPLETE behavior, grading integration, idempotent
  recompilation, score-correction recompilation, locked-result refusal, academic-context
  integrity, read/delete), `ResultSecurityAndFilterTest` (auth matrix, teacher scope, IDOR,
  filter-injection resistance, all nine filters, mass assignment, envelope shape),
  `ResultBulkCompileTest` (whole-class compile, per-row independent INCOMPLETE, active-only
  roster sweep, bulk recompilation idempotency, bulk authorization), `ResultIntegrityTest`
  (the raw unique-index constraint, idempotent recompilation at the service layer, simulated
  concurrent recompiles, locked-result refusal at the service layer, FK `restrictOnDelete` on
  all three references).
- **Full regression**: `php artisan test` - **1009 passed** (937 pre-existing + 72 new), zero
  failures, run twice (once before, once after Pint's own auto-fixes to confirm they changed
  nothing behavioral).
- **Migration test**: `php artisan migrate:fresh --seed` - clean run, no errors, run twice
  across this module's development (once mid-development, once as the final pre-commit check).
- **HTTP smoke tests**: `php artisan serve` against real Sanctum tokens (an admin, an
  authorized `TEACHING` staff member with a matching `TeacherAssignment`, an unauthorized
  `TEACHING` staff member with none), covering: authentication (`401` unauthenticated), a
  partial compile (CA scored, exam missing → `32.00%`, `INCOMPLETE`, `grade: null`), a complete
  recompile after the exam is scored (`80.00%`, `COMPILED`, `grade: A`, SAME result id),
  single-result retrieval, list, an idempotent duplicate compile (same id, no new row), an
  invalid academic context (mismatched class subject → `422`; mismatched term/session → `422`),
  an unauthorized teacher (`403`), an authorized teacher (`200`), and a whole-class bulk compile
  (`200`, one row, `status: COMPILED`, `error: null`).
- **Performance**: bulk compile's own `Enrollment::query()->where(...)->get()` plus a `map()`
  over `persist()` was inspected for N+1 risk - `calculateOutcome()`'s two queries (assessments,
  scores) run once PER enrollment inside the map (unavoidable: each enrollment's own scores
  differ), but the queries themselves are flat `WHERE ... IN` lookups against already-narrow,
  indexed columns (`class_subject_id`+`term_id` on `assessments`, `enrollment_id`+
  `assessment_id IN (...)` on `scores`), not a nested N+1 over an eager-loaded relation; the
  smoke-tested 1-student bulk compile confirmed the shape end-to-end.
- **Boundary/decimal/rounding tests**: the brief's own worked weighted example
  (`(16/20)*40 + (48/60)*60 = 80`) is pinned directly, alongside an unweighted-fallback example
  (`(18+72)/(20+80)*100 = 90`), a scored-but-unweighted-assessment exclusion case, a
  zero-scores case (`0.00%`, never an error), and a grading-boundary case (`75%` landing exactly
  on the standard scale's `70-100 → A` band).

## 13. Deviations

**What differed from the brief's own suggestion, and why:**

1. **`ResultStatus` is `INCOMPLETE`/`COMPILED`/`LOCKED`, not the brief's own suggested
   `DRAFT`/`SUBMITTED`/`APPROVED`/`PUBLISHED`/`LOCKED`.** **Cause**: that five-state shape
   describes MODULE 13's own workflow (approval and publication), not this module's; no
   existing enum in this project has that shape, so it is not an "established project workflow"
   this module is obligated to extend now. **Why this is correct**: building Module 13's states
   before Module 13 exists would be exactly the speculative-abstraction-ahead-of-need this
   project's own house rules forbid; `LOCKED` alone is kept as the one minimal hook Module 13
   will need, costing nothing today (§7).

2. **Bulk compile is deliberately NOT all-or-nothing**, where Module 10's own bulk score entry
   (the closest existing precedent) IS all-or-nothing. **Cause**: the two operations write
   fundamentally different kinds of data - Module 10 accepts new client-supplied marks, where a
   bad row is plausible evidence of a client mistake; Module 12 recomputes already-persisted,
   independently-valid history per student, where one student's legitimate incompleteness
   carries no information about any other student's readiness. **Why this is correct**: forcing
   an entire class's compile to fail because one student has no scores yet would make the
   day-to-day "compile my class as scores come in" workflow actively hostile to the exact
   partial-progress use case §9's missing-scores policy was designed to support.

3. **`REGISTRAR` holds full `compile` access**, where Module 08 and Module 11 (the closest
   existing precedents for a permission this specific) both narrow `REGISTRAR` to view-only.
   **Cause**: `teacher_assignments.*` and `grading_scales.*` are both genuine POLICY/staffing
   decisions outside `RoleSeeder`'s own stated registrar duties; compiling a result is
   mechanical execution of a process every input to which (assessments, weights, grading
   bands) was already configured by someone else in an earlier module. **Why this is correct**:
   it places result compilation in the same "admissions, enrollment and student records"
   bucket `RoleSeeder` already assigns to `REGISTRAR`, consistent with Module 09's own
   `assessments.*` split rather than Module 08/11's narrower one.

4. **The enrollment need not be `ACTIVE` to compile a result**, and **the class-subject chain
   is re-validated on every compile, not only the first** - both deliberate departures from
   `Score`'s own asymmetric create-vs-update behavior (create requires an `ACTIVE` enrollment;
   `update()` never re-derives academic context at all). **Cause**: compiling is aggregating
   ALREADY-RECORDED history for whichever placement the scores were recorded against, not
   asserting a new fact about a currently-active one, and recompiling deliberately re-derives
   the result from scratch against the CURRENT academic context every time - there is no
   "correction without re-deriving context" operation the way `Score::update()` has, because
   compile IS the derivation. **Why this is correct**: a withdrawn student's already-recorded
   term result must remain retrievable and correct, and a class subject retired mid-term must
   stop accepting NEW compiles immediately rather than only at the next unrelated write.
