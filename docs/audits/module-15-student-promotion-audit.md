# Module 15 — Student Promotion: Architecture Audit

Recording what happens to a student's placement going into a new academic session -
`PROMOTED`, `RETAINED`, `GRADUATED` or `NOT_ELIGIBLE` - built entirely on the existing
`Enrollment` architecture (Module 06), with no mutable "current class" ever added to `Student`.

## 1. Audit findings

Inspected before writing any code: `Student`, `StudentStatus`, `StudentService` (Module 04);
`Enrollment`, `EnrollmentStatus`, `EnrollmentService`, the `enrollments` migration (Module 06);
`ClassLevel`, `SchoolClass`, `Section` (Module 02); `AcademicSession`, `AcademicSessionStatus`
(Module 02); `ValidatesEnrollmentRecord`/`StoreEnrollmentRequest` (Module 06); every permission
seeder through Module 14; `ListRequest`; `ApiResponse`; `BusinessRuleViolation`; the full route
file; and the existing test suite (1149 tests, Modules 01-14 plus this module's own).

1. **`Student` has no `current_class_id` and none was added.** Confirmed by reading the model and
   its migration: the only placement-bearing table anywhere in this schema is `enrollments`. This
   is the single most load-bearing finding of the audit, and is exactly the architecture the
   brief's own critical rule names - see §2.
2. **`Enrollment` is already documented, in Module 06's own docblocks, as never mutated once
   created.** Both `EnrollmentStatus`'s own docblock and the `enrollments` migration's docblock
   state - written with foresight, before this module existed - that "promotion (a future module)
   creates a NEW row for the new session; it never mutates an existing one." This module
   implements exactly that foresight, unchanged.
3. **`enrollments.unique(student_id, academic_session_id)` already exists**, regardless of
   status, and applies to any row this module creates for free. This is one full layer of the
   idempotency guarantee §6 needed, at zero new schema cost.
4. **No class-progression graph exists anywhere.** Confirmed by grep: neither `SchoolClass` nor
   `ClassLevel` has a `next_class_id`/`next_class_level_id` column, or any other
   progression-ordering field beyond the purely cosmetic `sort_order` (documented elsewhere as
   display-only). This directly drove the decision in §4 to never compute a target class.
5. **`EnrollmentService::create()` already performs every check a target placement needs** - the
   target class is ACTIVE, its class level is ACTIVE, the enrollment date falls inside the target
   session, and the student doesn't already have a placement for that session (via the unique
   index, caught and translated into a `BusinessRuleViolation`). This module calls it directly for
   `PROMOTED`/`RETAINED`, rather than re-implementing any of those checks - the identical
   "one service calls another, never re-implements it" pattern `AdmissionService` already
   established for `StudentService::create()`.
6. **`StudentService::update(Student $student, array $attributes, ?StudentStatus $status = null)`
   already exists** as the sole mechanism for transitioning a student's status, and already
   guards against amending a terminal (`GRADUATED`/`WITHDRAWN`) record. This module reuses it
   unchanged for the `GRADUATED` transition - no second status-transition code path was written.
7. **No existing business rule anywhere ties promotion eligibility to a Result's finalization
   status.** Confirmed by re-reading `ResultService`, `ResultStatus`, and every Module 12-14
   permission seeder: nothing in this project defines "a student may only be promoted once their
   results are PUBLISHED." Inventing such a rule here would be exactly the "do not invent an
   automatic eligibility formula" the brief explicitly warns against - see §5.
8. **`ValidatesEnrollmentRecord`'s declarative validation style** (`Rule::exists()->where(...)`,
   scoped uniqueness via closures) is the exact pattern `PromoteStudentRequest` mirrors for
   `source_enrollment_id`/`target_academic_session_id`/`target_school_class_id`/
   `target_section_id`.
9. **`$this->route('paramName')` inside a FormRequest's `rules()` method, scoped against an
   already-bound route model, is an established pattern** (`UpdateScoreRequest`,
   `UpdateSubjectRequest`) - confirmed as pre-existing, not invented for this module, and reused
   identically in `PromoteStudentRequest` to scope `source_enrollment_id` to the `{student}` named
   in the URL.

## 2. Why `promotions` is a genuinely new table, not a reuse of `enrollments`

**The brief's own central architectural question.** `Enrollment` cannot represent a `GRADUATED`
or `NOT_ELIGIBLE` decision at all: both, by definition, produce no new placement, so there is no
enrollment row for either to attach a "this was decided" fact to. Two alternatives were
considered and rejected:

- **A new `EnrollmentStatus` value** (e.g. `GRADUATED`, `NOT_ELIGIBLE`) on the source enrollment
  itself. Rejected: this would mean *mutating* the source enrollment to record the outcome,
  directly violating the brief's own core rule that the old enrollment must remain untouched.
  `ACTIVE` on a historical enrollment already means exactly what it needs to mean - "this
  placement was never withdrawn or cancelled" - and overloading it further would break that
  existing, simple meaning.
- **No persistent record for `GRADUATED`/`NOT_ELIGIBLE` at all**, treating them as pure
  `Student.status`-only facts. Rejected: this would make `NOT_ELIGIBLE` unrecoverable
  (no queryable "who decided this, when, and why" trail) and would leave `GRADUATED` with no
  link back to the enrollment or target session the decision was made against.

**Resolution**: a new `promotions` table, one row per decision, referencing the source enrollment
always, and the target enrollment only when one exists. `target_academic_session_id` is its own
column - not read through `target_enrollment_id` - specifically because `GRADUATED`/
`NOT_ELIGIBLE` have no target enrollment to derive it from, and it is the one fact every decision
type needs.

## 3. Files

### 3.1 Created

- `database/migrations/2026_10_08_090000_create_promotions_table.php`
- `app/Enums/PromotionDecision.php`
- `app/Models/Promotion.php`
- `app/Services/Promotion/PromotionService.php`
- `app/Http/Requests/Promotion/{PromoteStudentRequest,PromotionListRequest}.php`
- `app/Http/Resources/Promotion/PromotionResource.php`
- `app/Http/Controllers/Api/V1/Promotion/PromotionController.php`
- `database/seeders/PromotionPermissionSeeder.php`
- `database/factories/PromotionFactory.php`
- `tests/Feature/Promotion/{PromotionTest,PromotionAuthorizationTest,PromotionIntegrityTest}.php`
- `docs/api/student-promotion.md`, this audit

### 3.2 Modified

- `routes/api.php` - `POST /students/{student}/promote` and the `promotions` route group
  (`GET /`, `GET /{promotion}`).
- `database/seeders/DatabaseSeeder.php` - `PromotionPermissionSeeder::class` added after
  `ReportCardPermissionSeeder::class`.
- `tests/TestCase.php` - same seeder added to the baseline `$this->seed([...])` list (now
  fourteen seeders).
- `tests/Pest.php` - `PromotionDecision`, `Promotion`, `PromotionService` imports;
  `promotionSession()`, `promotionContext()`, `promotePayload()`, `promotedStudent()` helpers.
- `docs/api/README.md` - module table and build-order entry.

### 3.3 NOT modified

`app/Models/Student.php`, `app/Models/Enrollment.php`, `EnrollmentService.php`,
`StudentService.php`, `EnrollmentStatus.php`, `StudentStatus.php`, and every other prior module's
file are **untouched**. No `current_class_id` (or any similarly-named column) was added to
`Student`; no new `EnrollmentStatus` case was added; no method on `Enrollment` was changed to
allow mutating a historical row's class or section. `PromotionService` calls
`EnrollmentService::create()` and `StudentService::update()` through their existing public
signatures only.

### 3.4 Deliberately not created

- **No `current_class_id` column, ever, on `Student` or anywhere else.** The brief's own explicit
  prohibition; see §0/§2.
- **No class-progression graph** (`next_class_id`/`next_class_level_id`). See §4.
- **No automatic eligibility formula** (e.g. `percentage >= 50`). See §5.
- **No bulk/batch promotion endpoint.** The brief explicitly permits deferring this
  ("if the existing requirements do not need bulk promotion yet, implement individual promotion
  and document bulk promotion as a future enhancement"). Every decision in this project's current
  requirements is made about one student, one enrollment, at a time; a bulk endpoint would need
  its own partial-failure/reporting design this module's brief does not ask for. Documented here
  as a deliberate, reasoned omission - the same discipline applied to every deferred feature in
  Modules 13/14 (no reject-transition, no ranking, no `overall_grade`).
- **No `promotions.approve` permission, no two-step decision workflow.** A promotion decision is
  a one-shot administrative act, matching the pattern `Admission` and `TeacherAssignment` already
  use - not a multi-stage approval chain like `Result`'s own workflow, which this module never
  reads or influences.
- **No reject/undo transition for an already-recorded decision.** Nothing in this project's
  existing requirements asks for one; a mistaken decision is corrected by recording a further,
  later one (possible for every decision except the terminal `GRADUATED`), not by mutating or
  deleting history.

## 4. Class progression: no graph, no computation, an explicit decision instead

**The brief's own instruction: class progression must come from actual database/configuration,
not hardcoded assumptions.** The audit's own finding (§1.4) is that no such configuration exists
anywhere in this schema. Building one (e.g. a `next_class_id` column, or a numeric-ordering
inference off `sort_order`, which is documented elsewhere as purely cosmetic) would mean
inventing a progression policy this module has no authority or requirement to set - directly the
kind of "hardcoded assumption" the brief warns against, just moved into a table instead of code.

**Resolution**: `PROMOTED`'s target class and section are always an explicit decision the caller
supplies, validated only for existence/active status/correct class-section pairing - never
computed. `RETAINED` is the one decision where a target is not a fresh choice: it is a direct,
zero-ambiguity copy of the source enrollment's own `school_class_id`/`section_id`, enforced by
making both fields **prohibited** (not merely optional) in `PromoteStudentRequest` when the
decision is `RETAINED` - a client cannot supply a different value even by accident.

## 5. Eligibility: no automatic formula, four explicit decisions

**The brief's own instruction: do not invent an automatic promotion formula (no `percentage >=
50` rule); support explicit promotion decisions instead.** Confirmed by audit (§1.7) that no
existing rule anywhere in this project ties promotion to a result's completion or score. No
precondition of that kind was added. All four decisions (`PromotionDecision` enum) are recorded
exactly as the caller names them, with no computed default and no implicit decision inferred from
any other table. The one guard that does exist - `PROMOTED` must target a class different from
the source - is not an eligibility formula; it is a data-integrity guard against mislabeling a
"stayed in the same class" outcome, which the brief's own decision taxonomy already names
`RETAINED` for.

`NOT_ELIGIBLE` was deliberately designed as a genuinely recordable, non-terminal fact - "this
student was not moved forward this round, for a reason outside this module's own knowledge" -
rather than merely a validation failure with no persistent trace. A later, different decision for
the same source enrollment (against a different target session) remains possible, unlike
`GRADUATED`, which is terminal via the existing `StudentStatus::GRADUATED` + `isTerminal()` guard
`StudentService::update()` already enforces.

## 6. Idempotency: two database-level layers, not an application check

**The brief's own instruction: idempotency via database constraints, not merely application-level
checks.**

- **`PROMOTED`/`RETAINED`** create a target enrollment through `EnrollmentService::create()`,
  which inherits `enrollments.unique(student_id, academic_session_id)` (Module 06) for free. A
  repeated attempt is caught as a `QueryException` (SQLSTATE 23000) inside that service and
  re-thrown as the same `BusinessRuleViolation` any unrelated duplicate-enrollment attempt already
  produces.
- **`GRADUATED`/`NOT_ELIGIBLE`** create no enrollment, so a second, purpose-built index -
  `promotions.unique(source_enrollment_id, target_academic_session_id)` - is this table's own
  backstop. `PromotionService::promote()` never *attempts* a second insert for a pair it already
  has one for (there is no "check then insert" race in the service's own logic); this index
  exists as the race-safe guarantee for two concurrent requests, exactly the same two-layer
  technique (service-level intent + database-level backstop) every prior module's own unique
  index already uses.

Both layers were verified empirically (manual tinker reproduction during development, then pinned
as `PromotionIntegrityTest` cases): a repeated `PROMOTED` call hits the enrollments-table message;
a repeated `NOT_ELIGIBLE`/`GRADUATED` call for the same pair hits the promotions-table message;
neither produces a duplicate row, and neither leaves a partial mutation (§7) behind.

## 7. Transactional safety

The entire body of `PromotionService::promote()` - creating the target enrollment (if any) and
transitioning `Student.status` (for `GRADUATED`) - runs inside one `DB::transaction()`. Confirmed
empirically that Laravel's nested-transaction savepoint mechanism correctly rolls back this outer
transaction when `EnrollmentService::create()`'s own inner `DB::transaction()` (called from
within it) throws - verified by a dedicated `PromotionIntegrityTest` case that races a `PROMOTED`
decision against an already-existing conflicting enrollment and asserts **both** that no
`Promotion` row was created **and** that the student's status was not left graduated from an
unrelated concurrent attempt.

## 8. Authorization

- **`SUPER_ADMIN`/`ADMIN`/`REGISTRAR`**: full access to both `promotions.view` and
  `promotions.create`, matching their existing unrestricted access to `enrollments.*` and
  `results.*`.
- **`STAFF`/`STUDENT`: nothing.** A deliberate departure from `scores.*`/`results.*` (where
  `STAFF` holds scoped access). Promotion is a whole-of-school, cross-subject academic-year
  decision; this project's only teacher-scope primitive (`TeacherAssignment`) is scoped to one
  class subject at a time and has no equivalent for "may decide this student's placement for next
  year." Unlike Module 14, no student self-access grant was added either: a promotion decision is
  an administrative act performed about a student, not a record a student reads about themselves
  the way a report card is - nothing in this module's brief asks for one, and inventing it would
  be an unrequested scope expansion.
- **IDOR**: `source_enrollment_id` is re-verified server-side against the `{student}` named in the
  URL, both declaratively (`PromoteStudentRequest`'s `Rule::exists()->where('student_id', ...)`,
  producing a **422** for a mismatched pair before the service is ever reached) and again inside
  `PromotionService::assertSourceEligible()` as defense in depth against a future caller that
  reaches the service directly.
- **Permission naming**: this project's own universal convention (`results.*`, `enrollments.*`,
  `report_cards.*`) uses the plural resource name; `promotions.view`/`promotions.create` follow
  that convention rather than the brief's own illustrative singular spelling
  ("promotion.view/create/approve"). No `.approve` permission exists - see §3.4.

## 9. Performance

`PromotionService::paginate()` eager-loads every relation the resource needs
(`sourceEnrollment.{student,academicSession,schoolClass,section}`,
`targetEnrollment.{academicSession,schoolClass,section}`, `targetAcademicSession`, `decidedBy`) in
one `with()` call, avoiding N+1 across a page of results - the identical eager-loading discipline
every prior module's own list service already applies. Filtering by `student_id` uses
`whereHas('sourceEnrollment', ...)` against `enrollments.student_id`, already indexed as part of
Module 06's own unique index. `GET /promotions/{promotion}` reuses the same `WITH` constant via
`loadMissing()` on the already route-bound model, the same "reuse the controller's resolved
instance" pattern every prior module's own `view()` uses.

## 10. Security review performed

- **IDOR**: `PromotionAuthorizationTest` verifies a caller cannot promote a student by naming a
  different student's enrollment id (422, not a silent cross-student mutation).
- **Authorization bypass**: `STAFF` (teaching and non-teaching) and `STUDENT` are all refused
  outright; a suspended account with a pre-suspension token is refused; a caller whose
  `promotions.create`/`promotions.view` grant is revoked individually is refused even while
  holding the other permission.
- **Historical-mutation protection**: `PromotionTest` pins that the source enrollment's `status`,
  `school_class_id`, `section_id` and `academic_session_id` are byte-for-byte unchanged after
  every decision type, and that results already compiled against the source enrollment are
  untouched.
- **Idempotency under repetition**: `PromotionIntegrityTest` drives repeated calls for the same
  pair through both database-level backstops (§6) and confirms no duplicate row and no partial
  student-status mutation.
- **FK integrity**: deleting a source enrollment, target enrollment, or target academic session
  still referenced by a `Promotion` row is refused (`QueryException`, `restrictOnDelete`);
  deleting the deciding user nulls `decided_by` rather than blocking (`nullOnDelete`), matching
  Module 13's own `submitted_by`/`approved_by` precedent.

## 11. QA review

- **Focused tests**: 53 new tests across `PromotionTest` (all four decisions, validation,
  result-integration/historical-safety, response structure - 33 tests), `PromotionAuthorizationTest`
  (permission matrix, IDOR, suspension, individual-permission revocation - 11 tests), and
  `PromotionIntegrityTest` (both idempotency layers, transactional rollback, FK deletion behavior,
  `decided_by` nulling - 9 tests).
- **Full regression**: `php artisan test` - **1149 passed** (1096 pre-existing through Module 14 +
  53 new), zero failures.
- **Migration test**: `php artisan migrate:fresh --seed` - clean run, no errors.
- **Static analysis**: `./vendor/bin/pint --test` - clean (two auto-fixable style issues found and
  fixed during development: an unused import in the test file, and a fully-qualified-strict-types
  fixer on the new model).
- **HTTP smoke test**: `php artisan serve` against a real Sanctum token, covering: retrieving the
  source enrollment, a valid `PROMOTED` decision (201, correct target enrollment created), the
  source enrollment confirmed unchanged afterward, a duplicate promotion attempt (422), an
  unauthenticated attempt (401), an invalid target class (422, correctly identifying both the
  invalid class and its now-unresolvable section), an invalid target section belonging to the
  wrong class (422), the list and single-show endpoints (200 with correct envelope shape), and a
  nonexistent promotion id (404).

## 12. Deviations

**What differed from the brief's own suggested shape, and why:**

1. **Permission names are plural** (`promotions.view`/`promotions.create`) rather than the
   brief's own illustrative singular spelling. **Cause**: this project's universal existing
   convention. **Why this is correct**: consistency with every other module's own permission
   naming outweighs matching the brief's illustrative (not prescriptive) example.
2. **No `promotions.approve` permission or two-step workflow**, though the brief's illustrative
   permission list included one. **Cause**: nothing in this project's actual requirements asks for
   a multi-stage approval chain for a promotion decision, unlike `Result`'s own workflow
   (Module 13), which exists for a different, already-specified reason. **Why this is correct**:
   inventing an approval workflow here would be unrequested scope, not a described requirement.
3. **Bulk promotion is deferred**, exactly as the brief's own permission allows. **Cause**: no
   existing requirement calls for it yet, and a correct bulk design (partial-failure reporting,
   per-row validation) is substantial enough to warrant its own dedicated module once actually
   needed. **Why this is correct**: the brief explicitly names this as an acceptable deferral.
4. **No student self-access to `GET /promotions`**, unlike Module 14's own student-facing
   `report_cards.view` grant. **Cause**: nothing in this module's brief asks for one, and a
   promotion decision is an administrative act about a student, not a record analogous to a
   report card that a student reads about themselves. **Why this is correct**: granting it would
   be an unrequested scope expansion with no corresponding requirement.
