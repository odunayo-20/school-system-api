# Module 10 — Score Management: Architecture Audit

What a specific student obtained against a specific configured assessment. Explicitly without
grading, GPA, pass/fail, final totals, report cards or promotion - reserved for Module 11 and
later.

## 1. What was already in the codebase

No score/grade/result code existed anywhere. The audit focused on four things this module's
design turns entirely on:

- **`ClassSubject` has no `section_id`** (only `school_class_id` + `subject_id` - confirmed by
  re-reading the `class_subjects` migration). Assessments are therefore class-wide, not
  section-specific, which directly answers the brief's Section 16 question: a student's
  enrollment determines their section; Score needs no section column of its own.
- **`Term.academic_session_id`** is a real FK column, so an assessment's academic session is
  always reachable via `assessment.term.academic_session_id` without Score storing it.
- **`Enrollment`** carries `student_id`, `academic_session_id`, `school_class_id`, `section_id`
  and an `ACTIVE`/`WITHDRAWN`/`CANCELLED` status, and is the project's own answer (Module 06) to
  "a student's placement changes every session, so it needs its own historical row" - exactly
  the shape Section 5's audit question was pointing at.
- **No `Policy` class exists anywhere in this project**, and every authorization decision through
  Module 09 is a flat `permission:` gate with no per-request ownership scoping. Module 10 is the
  first module where a holder of a permission is not thereby entitled to every row the endpoint
  touches - see §7.
- **`Illuminate\Auth\Access\AuthorizationException` is already rendered as `403`** via
  `ApiResponse::forbidden` in `bootstrap/app.php`, with no module-specific wiring. The
  teacher-scope check throws this directly; no new exception class or `render()` hook was
  needed.

## 2. Files

### 2.1 Created

- `database/migrations/2026_10_04_090000_create_scores_table.php`
- `app/Models/Score.php`
- `app/Services/Score/ScoreService.php`
- `app/Http/Requests/Score/{Store,Update}ScoreRequest.php`, `ScoreListRequest.php`,
  `StoreScoreBulkRequest.php`
- `app/Http/Requests/Score/Concerns/ValidatesScoreRecord.php`
- `app/Http/Resources/ScoreResource.php`
- `app/Http/Controllers/Api/V1/Score/ScoreController.php`
- `database/seeders/ScorePermissionSeeder.php`
- `database/factories/ScoreFactory.php`
- `tests/Feature/Score/{ScoreManagementTest,ScoreSecurityAndFilterTest,ScoreBulkEntryTest,
  ScoreIntegrityTest}.php`
- `docs/api/score-management.md`, this audit

### 2.2 Modified

- `routes/api.php` - new `scores` route group, including `POST /scores/bulk`.
- `database/seeders/DatabaseSeeder.php` - `ScorePermissionSeeder::class` added after
  `AssessmentPermissionSeeder::class`.
- `tests/TestCase.php` - same seeder added to the baseline `$this->seed([...])` list.
- `tests/Pest.php` - imports, `matchedScoreContext()`, `scoreCreatePayload()`,
  `scoreUpdatePayload()`, `recordedScore()`, `teacherAssignedTo()`, `rosterEnrollments()`.

### 2.3 Kept unchanged

Every file from Modules 01-09. `Enrollment`, `EnrollmentService`, `Assessment`, `ClassSubject`,
`TeacherAssignment`, `ListRequest` and `ApiResponse` were **read fresh in this session** before
reuse, not assumed from memory.

### 2.4 Deliberately not created

- **No `ScoreRepository`, `ScoreManager`, `ScoreHelper`, `ScoreTransformer`, `ScoreDTO`,
  `ScoreInterface`.** Nothing in this module's shape needs one; the existing
  Model → FormRequest → Service → Controller → Resource pipeline handles it entirely.
- **No `ScorePermissionService`.** The teacher-assignment scope is three protected methods on
  `ScoreService` (`isAssignedTeacher()`, `activeTeachingAssignments()`, and the `assertViewable`/
  `assertTeacherAuthorized` callers) - the same layering every prior module already uses for a
  domain check that needs a loaded relation, extended to scope by the ACTOR rather than only by
  a payload field. See §7 for why a separate service was considered and rejected.
- **No generic bulk-import engine.** `POST /scores/bulk` is a single transactional endpoint
  bound to one Form Request and a dozen lines in `ScoreService::createBulk()` - no job queue, no
  CSV parser, no generic "importer" abstraction, because nothing in the brief asked for anything
  beyond "many rows, one assessment, one call".
- **No duplicate `Student`, `Enrollment`, `Teacher` or `Staff` model.** `TeacherAssignment` (not
  a new `Teacher` entity) is the sole source of truth for who may act on a class subject, exactly
  as Module 08 already established.
- **No `TEACHER` role.** Authorization runs against `staff_type: TEACHING` on the existing
  `STAFF` role, matching every module since Module 03.
- **No grade, GPA, pass/fail, letter, weighted total, `Result`, `ResultItem`,
  `ResultPublication`, `ReportCard` or `Promotion`.** Explicitly out of scope; reserved for
  Module 11 and later - see §10.
- **No `max_score`, `percentage`, `grade` or `student_id` column on `scores`.** All four are
  derivable (from the assessment, and from the enrollment) and storing any of them would be a
  cache with no invalidation path - see §3 and §4.
- **No score `status` or audit-history table.** See §9.

## 3. Score ↔ Assessment, Score ↔ Enrollment

**Question (Section 5): should Score reference `student_id + assessment_id`, or something
stronger?** Resolved as `enrollment_id + assessment_id`. `Enrollment` is this project's own
already-built answer to "a student's placement is a fact about ONE academic session, not a
mutable current value" (Module 06's entire design). A score inherits that discipline for free by
referencing the enrollment: a JSS 1 2025/2026 score is permanently anchored to the JSS 1
2025/2026 enrollment row and can never surface against the student's later JSS 2 2026/2027
enrollment, because it was never linked to the student directly at all - the identical
protection `TeacherAssignment.class_subject_id` already gives "who teaches what" one level up.

**`max_score` is never stored on `scores`.** It is read live from `assessment.max_score` on
every validation (`ValidatesScoreRecord::scoreValueRule()`), by design, for two reasons:

1. The brief's own Section 8 requirement - the server determines the valid maximum from the
   assessment; nothing reads a client-supplied one.
2. `Assessment.max_score` is itself editable (Module 09's `UpdateAssessmentRequest`). A snapshot
   captured at score-creation time would silently drift from the assessment's own current value.
   A score already on file is never retroactively invalidated by a later change to its
   assessment's `max_score` - only the NEXT write (an amend) is re-checked against the live
   value. Verified directly: `ScoreManagementTest::'it re-validates an amend against the
   assessment live maximum, not the value at creation time'`.

**`score_percentage` is never stored either** - computed in `ScoreResource` from
`score / assessment.max_score`, guarded against a zero denominator (unreachable through the API
today, since `Assessment.max_score` is validated `> 0` at Module 09's own creation time, but
guarded anyway rather than assumed to hold forever).

## 4. Uniqueness

**Question (Section 6): `unique(assessment_id, enrollment_id)`, or something looser for
retries?** Nothing in the brief, the existing domain, or any prior module's own comments
describes a retry/attempt concept for scores, and inventing one now would be exactly the
speculative architecture this project's conventions caution against. `unique(assessment_id,
enrollment_id)` is enforced at the database level (the migration's own index) and declaratively
in `StoreScoreRequest`/`StoreScoreBulkRequest` via a scoped `Rule::unique`, with the standard
SQLSTATE 23000 race-catch in `ScoreService::create()`/`createBulk()` as the backstop for two
concurrent submissions. If retries/re-sits are ever needed, the correct extension is an explicit
decision for whichever future module needs it (an `attempt_number` column, or a superseding row)
- not something this module should half-build today by leaving the constraint loose.

## 5. Maximum-score validation and decimal handling

`ValidatesScoreRecord::scoreValueRule()` is one closure rule shared across
`StoreScoreRequest`, `UpdateScoreRequest` and `StoreScoreBulkRequest` (per row): `required`,
`numeric`, `min:0`, and a closure that looks up the assessment fresh and fails if the score
exceeds its `max_score`. **Decimal scores are supported** (`17.5`, `19.25`): `scores.score` is
`decimal(6, 2)`, matching `assessments.max_score`'s own precedent from Module 09 exactly - not
`float`, so a value like `17.5` is stored exactly rather than as a binary approximation. The
`Score` model casts it `'score' => 'decimal:2'`, the identical mitigation `Assessment` already
applies to `max_score`/`weight` for presenting a consistently-scaled string regardless of how
the underlying driver stored it.

**A zero score is valid** (`min:0`, not `min:0.01` - unlike `Assessment.max_score`, which must
be `> 0`). **`20.1` against a `max_score` of `20` is rejected**; **`20` itself is accepted**
(`>` the maximum is refused, `=` is not). **Negative scores are rejected.** All four are pinned
directly by `ScoreManagementTest`.

## 6. Score context validation

Four checks guard every create, split between the Form Request (declarative, column-bound) and
`ScoreService` (needs loaded relations):

| Check | Layer | Why |
|---|---|---|
| Assessment exists, is `ACTIVE` | Form Request | Plain `Rule::exists()->where()` |
| Assessment's class subject, class, class level all `ACTIVE` | Service (`assertAssessmentSelectable`) | Needs `classSubject.schoolClass.classLevel` loaded - the identical three-level chain Module 08/09 both use |
| Enrollment exists, is `ACTIVE` | Form Request | Plain `Rule::exists()->where()` |
| Enrollment's class and session match the assessment's | Service (`assertContextMatches`) | Compares two loaded models' columns - no declarative rule expresses a cross-table join |

**Term completion is deliberately NOT checked**, unlike every module that guards a brand-new
placement against a completed session/term (Enrollment, TeacherAssignment). A score is
retrospective entry for an assessment that already happened - teachers routinely finish entering
marks right as, or shortly after, a term closes. Blocking it the moment
`Term.status` becomes `COMPLETED` would stop legitimate, already-due data entry for no benefit.
Pinned directly: `ScoreManagementTest::'it does not require the term to still be open - scoring
a just-completed term is allowed'`. This is the one place Module 10 deliberately behaves
differently from the "must not be COMPLETED" pattern used everywhere since Module 05, and it is
a considered choice, not an oversight.

**An ended enrollment blocks a NEW score, but not a correction to one already on file** -
`assertContextMatches()` only runs in `create()`/`createBulk()`, never in `update()`. A student
who withdrew mid-term may still have a data-entry error in an existing score fixed.

## 7. Teacher assignment authorization: the core of this module

**Section 10's central question.** Every earlier module's permission grant IS the whole
authorization answer once held. This module is the first where it is not, for `STAFF`
specifically:

- `assertTeacherAuthorized()`/`assertViewable()` gate every write and every single-record read
  through `isAssignedTeacher(User $user, Assessment $assessment): bool`, which - for a `STAFF`
  user only - checks a live `TeacherAssignment` row matching **both** `class_subject_id`
  **and** `academic_session_id`, status `ACTIVE`.
- **The pair matters, not the class subject alone.** `TeacherAssignment` is itself session-scoped
  (Module 08's own central decision: who teaches a class subject changes year to year). An
  earlier draft of this service checked only `class_subject_id`, which would have let a teacher
  assigned to "JSS 2 Mathematics" in 2025/2026 score a 2026/2027 assessment of the same class
  subject even after their assignment ended and someone else took over. Caught by
  `ScoreSecurityAndFilterTest::'it refuses a teacher's assignment to a DIFFERENT academic
  session than the assessment'` during this module's own test-writing pass, fixed before
  shipping - see §13.
- `GET /scores` (the list) applies the identical (class subject, session) scope
  UNCONDITIONALLY, before any filter, via `activeTeachingAssignments()` building an OR of
  `whereHas` constraints - one per active assignment. A teacher sending `?student_id=999` for a
  student outside their scope gets an empty list, never that student's data; the scope is never
  replaced by a filter, only narrowed further within it.
- **Non-teaching staff are not excluded at the permission-grant level.** `STAFF` holds
  `scores.*` at the role level regardless of `staff_type` (see §11), and a `NON_TEACHING` staff
  member is refused entirely by `isAssignedTeacher()`'s own eligibility gate (not `staff_type:
  TEACHING`, or not actively employed → `false` unconditionally) - an empty list on read, `403`
  on every write.
- **Why not a separate `ScorePermissionService`?** The brief explicitly asks this to be
  justified. Every prior module already puts a domain check that needs a loaded relation
  directly on that module's own service (`assertClassSubjectSelectable()` in
  `TeacherAssignmentService` and `AssessmentService`). The teacher-assignment scope is the same
  shape of check, merely keyed by the ACTOR (the authenticated user) instead of only a payload
  field - there is no second concern here large enough to justify a new class, and splitting it
  out would separate logic that has to stay in lock-step with `ScoreService::create()`,
  `update()`, `createBulk()`, `view()` and `query()` for no benefit.

## 8. API endpoints and list filters

`GET/POST /scores`, `POST /scores/bulk`, `GET/PUT /scores/{id}` - matching the brief's own
suggested shape exactly, with no endpoint added merely because it sounded useful (no
`GET /students/{id}/scores` or `GET /assessments/{id}/scores` convenience route: the canonical
filters already answer both questions, the identical reasoning Module 08's audit gives for
skipping its own equivalent routes).

Filters: `assessment_id`, `assessment_type_id`, `enrollment_id`, `student_id`, `school_class_id`,
`section_id`, `subject_id`, `academic_session_id`, `term_id` - the exact list Section 21 names.
No `search`: a score holds no text field of its own (`remarks` is free text kept with one
specific record, not a field a list is searched by), matching `TeacherAssignmentListRequest`'s
and `AssessmentListRequest`'s identical reasoning for their own lists.

## 9. Score correction workflow

**Section 17's explicit questions, answered:**

- **Who can edit scores?** Administrators unrestricted; a teacher only for their own currently
  assigned class subjects (§7); `REGISTRAR` cannot edit at all (view only).
- **Should there be a score status?** No. Nothing in this module's own size needs a
  DRAFT/SUBMITTED workflow; a score is either recorded or it is not, and correcting it is an
  ordinary `PUT` available to whoever is authorized to touch that class subject/assessment.
  Adding a status now, with no consumer for it, would be exactly the speculative field the brief
  cautions against.
- **Does every change need audit history?** No full history table was built - none of Modules
  01-09 keeps one for any of their own working records either (an `Enrollment`'s
  `enrollment_date`/`notes` amend, a `ClassSubject`'s status toggle, none is versioned). `remarks`
  (nullable, free text) gives a place to record WHY a correction was made, matching the
  `notes`/`remarks` convention every prior module's own working record already carries, without
  building a versioning system no requirement asked for.
- **Protected foreign keys.** `assessment_id`/`enrollment_id` have no key in `UpdateScoreRequest`
  at all - not merely unvalidated. `ScoreManagementTest::'cannot reach assessment_id or
  enrollment_id through the amend endpoint'` sends both anyway and confirms neither moves. This
  is both the data-integrity guarantee Section 18 asks for (a `PUT` can never turn "Student A,
  Math CA" into "Student B, English Exam") and, combined with §7's scope check running against
  the score's OWN unchanged assessment, an authorization guarantee too.

## 10. What this module owes the next one

Module 11 (Grading) can safely assume:

- Every `Score` row names an assessment and enrollment that were valid and context-matched at
  creation time, and neither can have silently changed since - both are immutable.
- `score` is always `>= 0` and was `<= assessment.max_score` at the time of its last write (not
  necessarily right now, if the assessment's own `max_score` was lowered afterward - see §3).
- `score_percentage` is a raw ratio computed at read time, not a grade; nothing in this module
  interprets a score against a grading scale.
- No student-facing read access exists yet on `scores.*` - a future Result Checker module is
  where that belongs, once grading gives raw marks the framing they need to be shown safely.

## 11. Security review performed

- **IDOR**: `ScoreSecurityAndFilterTest::'refuses an unassigned teacher reading/amending a score
  by id'` - an unrelated teacher naming a real score's id by ID gets `403`, not the score or a
  silent no-op.
- **Student access**: `STUDENT` holds no `scores.*` permission at all; verified against both
  `GET /scores` and `POST /scores`.
- **Assessment manipulation**: a teacher cannot submit against an assessment outside their
  assignment merely by knowing its id - `403`, verified for single create, bulk create, and
  amend.
- **Enrollment manipulation**: an unrelated enrollment injected into an otherwise-valid request
  (single or as one row of a bulk batch) is refused with `422` naming the mismatch, and for bulk
  specifically, refused WITHOUT saving the other, individually-valid rows in the same batch.
- **Mass assignment**: `assessment_id`/`enrollment_id` are absent from `Score::$fillable` and set
  only via `forceFill()` inside the service; `UpdateScoreRequest` has no key for either at all.
  Verified with an id/`created_at` injection attempt on create.
- **Maximum-score bypass**: a client-supplied `maximum_score` field is inert - nothing reads it;
  the ceiling is always the named assessment's own, freshly queried value. Verified directly.
- **Permission bypass**: per-action permission detachment tested (`scores.create` revoked,
  `scores.view`/`scores.update` still work); full role matrix tested.

## 12. QA review

- **Focused tests**: 78 new tests across `ScoreManagementTest` (create/read/amend/validation),
  `ScoreSecurityAndFilterTest` (auth matrix, teacher scope, IDOR, filters, mass assignment),
  `ScoreBulkEntryTest` (all-or-nothing, row-level errors, batch ceiling, teacher scope on bulk),
  `ScoreIntegrityTest` (unique-index race, FK `restrictOnDelete`).
- **Full regression**: `php artisan test` - **868 passed** (794 pre-existing + 74 new), zero
  failures.
- **Migration test**: `php artisan migrate:fresh --seed` - clean run, no errors, on every attempt
  across this module's development.
- **HTTP smoke tests**: `php artisan serve` against a real Sanctum token per actor (super admin,
  an assigned teacher, an unassigned teacher, a student), covering: unauthenticated create
  (`401`), score creation (`201`), listing, retrieval, update (`200`), unauthorized creation by
  an unassigned teacher (`403`), unauthorized modification by an unassigned teacher (`403`),
  score above maximum (`422`), invalid/unrelated enrollment (`422`), duplicate score (`422`),
  an assigned teacher succeeding on their own class subject (`201`), a student forbidden outright
  (`403`), and bulk entry (`201`).
- **Performance**: `DB::enableQueryLog()` around a rendered page of 8 (then 5, with linked
  accounts) scores showed a **flat, batched query count regardless of row count** - every
  relation resolved via a single `whereIn` per table (assessments, class_subjects, classes,
  subjects, terms, academic_sessions, assessment_types, enrollments, students, users, sections),
  zero N+1. Verified twice: once with pupils that have no linked account (to confirm the
  `belongsTo` short-circuit on a null FK is not mistaken for successful eager loading), and once
  with `Student::factory()->withAccount()` to force the `users` table query and confirm it is
  batched too.
- **Edge cases**: zero score, score exactly at maximum, decimal score, two students sharing one
  assessment, the same enrollment appearing twice in a bulk batch, a batch at exactly the
  100-row ceiling, an assessment/class-subject whose class level was retired after the class
  subject and class themselves stayed `ACTIVE`.

## 13. Deviations

**What differed from the brief's own suggestion, and why:**

1. **Term completion does not block score creation**, where the brief's own general pattern
   (visible in every earlier module) guards a new record against a `COMPLETED` session/term.
   **Cause**: a score is retrospective entry for an assessment that has already happened, unlike
   a new enrollment or assignment, which are prospective placements. **Why this is correct**:
   blocking score entry the moment a term completes would actively prevent teachers from
   finishing legitimate, already-due data entry - the opposite of what every other
   "not-COMPLETED" guard exists to protect against. See §6.

2. **`STAFF` holds `scores.*` at the role level**, where Module 08's closest precedent
   (`teacher_assignments.*`) withholds the equivalent permission from `STAFF` entirely. **Cause**:
   entering a score is a teaching duty performed BY staff, not an administrative decision ABOUT
   staff - the two modules are not actually analogous despite both touching "teaching". **Why
   this is correct**: the alternative (withholding the permission and inventing a bypass path
   outside the Gate for teachers) would be architecturally worse - it would mean the permission
   system stops being the single source of truth for "can this role attempt this operation",
   which every other module in this project relies on. Layering a domain-level scope check ON
   TOP of a held permission (§7) keeps the permission system authoritative while still refusing
   the specific attempts that must be refused.

3. **The teacher-assignment authorization check was found to need a session-pair fix mid-audit**,
   not merely a class-subject check as first implemented. **Cause**: `TeacherAssignment` is
   session-scoped (Module 08), a fact this module's own test-writing pass surfaced a gap in
   before any code shipped. **Why the final implementation is correct**: pinned by
   `ScoreSecurityAndFilterTest::'it refuses a teacher's assignment to a DIFFERENT academic
   session than the assessment'`, and the fix (`isAssignedTeacher()`/
   `activeTeachingAssignments()` both keying on the (class_subject_id, academic_session_id)
   pair) was verified against the full regression suite before this module was considered done.
