# Module 14 — Report Cards: Architecture Audit

A structured, read-only presentation of a student's finalized subject results for one
enrollment and one term - built entirely on Module 12's `Result` rows and Module 13's
publication workflow, with no second calculation engine and no new table.

## 1. Audit findings

Inspected before writing any code: `Result`, `ResultService`, `ResultStatus`, `ResultResource`
(Module 12/13); `Enrollment`, `Student`, `User` (Modules 04/06); `ClassSubject`, `Subject`
(Module 07); `Assessment` (Module 09); `GradingScale`/`GradingScaleItem` (Module 11); every
existing `Resource` class; `ListRequest`; `ApiResponse`; `AuthServiceProvider`/
`EnsureUserHasPermission`; `bootstrap/app.php`'s exception rendering; the full route file; and
the existing test suite (1049 tests, Modules 01-13).

1. **There is no `ResultItem` model or table.** Module 12 deliberately keeps ONE table,
   `results`, keyed `(enrollment_id, class_subject_id, term_id)` - one row IS one subject's
   result. A "report card" is therefore not a single row's presentation; it is the aggregate of
   every `Result` row SHARING one `enrollment_id` and one `term_id`, across different
   `class_subject_id`s. This is the single most load-bearing finding of the audit - see §3.
2. **Results are snapshots, not recalculated.** `percentage`/`grade`/`grade_point`/`remark` are
   persisted at compile time (Module 12) and never re-derived at read time. Confirmed by
   re-reading `ResultService::calculateOutcome()`/`persist()` fresh in this session.
3. **Completeness is `ResultStatus`** (`INCOMPLETE`/`COMPILED`/`SUBMITTED`/`APPROVED`/
   `PUBLISHED`/`LOCKED`, Modules 12-13). Only `PUBLISHED` and `LOCKED` represent a result no
   longer subject to change - Module 13's own `isRecompilable()` guard refuses a recompile for
   both, plus `SUBMITTED`/`APPROVED`. This is the exact, pre-existing threshold this module
   reuses for "finalized" - see §4.
4. **Teacher/class/subject authorization** is `TeacherAssignment`, scoped to exactly one
   `(class_subject_id, academic_session_id)` pair (Module 08, reused unchanged by Module 10 and
   12). Confirmed: **no "form teacher" or "class teacher" concept exists anywhere** that could
   safely be widened into "sees every subject in a class." This finding directly drove the
   decision in §7 to withhold `STAFF` access entirely.
5. **`User::student(): HasOne` already exists** (Module 04), explicitly documented in its own
   docblock as "reserved for the portal module" with no existing consumer. This module is the
   first to read it - see §7.
6. **No comment system, no attendance table, and no ranking/position concept exist anywhere in
   this codebase.** Confirmed by grep across every migration and model; none of §12 (summary),
   §13 (ranking) or §14 (comments) of this module's own brief has existing data to expose.
7. **Every Resource in this project explicitly avoids a second, divergent field list for a
   nested record** (`EnrollmentResource`, `ClassSubjectResource`, etc. all say so in their own
   docblocks) - directly informing §10's resource design.
8. **No existing precedent for a manually-constructed `LengthAwarePaginator`** - every prior
   list endpoint calls `Builder::paginate()` directly. This module is the first to build one by
   hand, for the reason given in §11.

## 2. Files

### 2.1 Created

- `app/Services/ReportCard/ReportCardService.php`
- `app/Http/Controllers/Api/V1/ReportCard/ReportCardController.php`
- `app/Http/Resources/ReportCard/{ReportCardResource,ReportCardSummaryResource}.php`
- `app/Http/Requests/ReportCard/ReportCardListRequest.php`
- `database/seeders/ReportCardPermissionSeeder.php`
- `tests/Feature/ReportCard/{ReportCardTest,ReportCardAuthorizationTest,ReportCardHistoryTest}.php`
- `docs/api/report-cards.md`, this audit

### 2.2 Modified

- `routes/api.php` - new `report-cards` route group (two `GET` routes).
- `database/seeders/DatabaseSeeder.php` - `ReportCardPermissionSeeder::class` added after
  `ResultPermissionSeeder::class`.
- `tests/TestCase.php` - same seeder added to the baseline `$this->seed([...])` list (now
  thirteen seeders).
- `tests/Pest.php` - `ResultStatus` import, `reportCardContext()` helper.
- `docs/api/README.md` - module table and build-order entry.

### 2.3 NOT modified

`app/Models/Result.php`, `ResultService.php`, `ResultResource.php`, and every other Module
12/13 file are **untouched**. This module reads `Result` through its own existing public
relations (`enrollment`, `classSubject`, `term`) and columns only; no method, cast, or column
was added to accommodate it.

### 2.4 Deliberately not created

- **No `report_cards` migration/table.** See §2 for why: no independent persistent data exists
  for it to hold. Every summary value is computed at request time from already-persisted
  `Result` rows.
- **No second calculation engine, no `ReportCardCalculator`, no re-implementation of
  weighting or grading.** `ReportCardService` performs exactly two arithmetic means over
  already-final numbers - see §12 - and nothing else numeric.
- **No `ReportCard` Eloquent model.** There is nothing for one to represent: no table, and the
  read model is assembled per-request from `Result`, `Enrollment` and `Term`.
- **No workflow/status of its own, no Policy class, no new Gate ability shape.** Availability
  is derived entirely from `Result.status` (§4); authorization reuses the exact `permission:`
  Gate mechanism every module since Module 01 already uses.
- **No ranking/position, no comments schema or endpoints, no attendance placeholder, no
  per-assessment breakdown, no `overall_grade`.** Each is a considered, documented omission -
  see §12-14 of the API doc and §12 below - not an oversight.
- **No parent/guardian access, no Result Checker, no Report Card PDF/export.** Explicitly out
  of this module's scope per its own brief.

## 3. Report-card scope: an aggregate over `Result`, not a new shape

**The brief's own §3 central question** - does the existing `Result` structure represent one
student+one subject, one student+one term, or another scope? **Answer, from the audit: one
student + one subject + one term, per row** (§1.1). A report card - "everything for this
student, this term" - is therefore not a shape `Result` already has; it is a QUERY across
multiple `Result` rows that happen to share `(enrollment_id, term_id)`. `ReportCardService`
does exactly this: `Result::where('enrollment_id', ...)->where('term_id', ...)->whereIn(
'status', [PUBLISHED, LOCKED])->get()`, then groups the output for presentation. No new
aggregate table was needed to express this - a `WHERE` clause on two already-indexed columns
(`results`' own `(class_subject_id, term_id)` index, plus its primary unique index's leftmost
column `enrollment_id`) already answers it directly.

## 4. Availability threshold, applied uniformly

**The brief's own §16 central question**: at what point in Module 13's workflow does a result
become presentable as a finalized report card? **Resolution: `PUBLISHED` or `LOCKED`, and
nothing else - applied identically to every caller, including `SUPER_ADMIN`.**
`INCOMPLETE`/`COMPILED`/`SUBMITTED`/`APPROVED` results are invisible to this module's
endpoints, not merely hidden behind an extra flag. A "preview my draft report card" mode for
staff was considered and rejected: an administrator wanting to see in-progress data already has
`GET /results` (Module 12), which exposes every status; conflating that with "the finalized
report card" endpoint would blur the one distinction this module exists to draw. This threshold
is enforced in exactly one place - the `AVAILABLE_STATUSES` constant on `ReportCardService` -
so there is one authoritative answer to "is this shown yet," not one per caller type.

A term whose subjects mix finalized and not-yet-finalized results is not rejected outright -
the finalized subset is shown, and `summary.subjects_count` counts exactly that subset. A term
with ZERO finalized subjects yields **404** from the identifying-pair endpoint (a singular
lookup with nothing to return - see the API doc §1.2) and simply does not appear as a row in
the history listing (a list endpoint, where "nothing yet" is an empty array, not an error -
matching this project's own established list-vs-singular distinction).

## 5. Historical safety

**The brief's own §5, its most emphatic section.** Every guarantee here is inherited from
Module 12's snapshot design and Module 13's `isRecompilable()` guard, extended by nothing new:

- **Score changes**: irrelevant to an already-`PUBLISHED`/`LOCKED` result, because nothing
  recalculates a `Result` outside an explicit `compile()`, and `compile()` itself refuses to
  touch a result past `COMPILED` (Module 13). Pinned directly:
  `ReportCardTest::'never recalculates a percentage...'` and
  `'...remains stable for a locked result even after later score and assessment changes'`.
- **Grading-scale changes**: `GradingService::calculate()` runs only inside `compile()`, so
  retiring or editing a scale after publication has zero effect on an already-resolved
  `grade`/`grade_point` - pinned by `'remains stable after a later, unrelated grading-scale
  change'`.
- **Assessment/subject/class/enrollment changes**: none of these is ever re-read by this
  module; it reads only the `Result` row's own persisted columns plus display-only nested
  records (`classSubject.subject`, `enrollment.schoolClass`, etc.) for LABELING, never for
  recomputation.
- **Result locking**: a `LOCKED` result is `PUBLISHED`'s own successor state and is included on
  exactly the same terms as `PUBLISHED` - no special-casing needed, since both already sit
  inside `AVAILABLE_STATUSES`.

No new migration or column was required to achieve any of this: Module 12 and 13's own
snapshot architecture already made a report card's historical stability a direct consequence of
reading it correctly, not something Module 14 had to build.

## 6. Report card status: none

**The brief's own §6 central question.** A report card has no lifecycle of its own. It derives
its availability entirely from `Result.status` (§4) rather than duplicating
`DRAFT`/`SUBMITTED`/`APPROVED`/`PUBLISHED`/`LOCKED` on a second table or field. There is
structurally nothing for a second status to track: a report card is not created, submitted, or
approved - it is computed, on every request, from whatever `Result` rows currently qualify.

## 7. Authorization

**The brief's own §7 central question - who should access report cards - and its explicit
warning against inventing unsupported scopes.**

- **`SUPER_ADMIN`/`ADMIN`/`REGISTRAR`**: unrestricted, matching their existing `results.view`
  access exactly. `REGISTRAR`'s grant is the same "admissions, enrollment and student records"
  territory `RoleSeeder` already names for the role.
- **`STAFF`: NOTHING.** A report card spans every subject in a term; this project's only
  teacher-scope primitive is scoped to ONE class subject at a time (§1.4). Granting `STAFF`
  access here would either leak subjects a teacher was never assigned to teach, or require
  inventing a "class teacher" scope this project's architecture does not have - exactly the
  unsupported access pattern the brief's own §7 warns against building. This is enforced
  TWICE: `ReportCardPermissionSeeder` grants `STAFF` nothing, and
  `ReportCardService::assertEnrollmentViewable()`/`assertStudentViewable()` explicitly refuse
  the role outright as a second, service-layer gate - defense in depth against a future
  `directPermissions` override, pinned by
  `ReportCardAuthorizationTest::'refuses teaching staff outright, even a teacher assigned to
  one of the subjects on the card'`.
- **`STUDENT`: granted, self-scoped.** `User::student()` (§1.5) is read for the first time by
  this module. `report_cards.view` is granted at the role level (coarse), and
  `ReportCardService` checks `$user->student?->id === $enrollment->student_id` (fine) - the
  identical two-layer pattern `STAFF` already gets over `results.*`, applied here to `STUDENT`
  for the first time in this project. An attempt to view or list another student's data is
  **403** (`AuthorizationException`, this project's existing IDOR-refusal shape), never a
  silent redirect to someone else's data - pinned by three dedicated IDOR tests.
- **This is deliberately narrower than the "future Result Checker" every earlier module's docs
  deferred student access to.** That module (unauthenticated, PIN/reference-code based) is a
  different mechanism for a different audience. This module's student access is the
  authenticated student's OWN account reading their OWN already-`PUBLISHED`/`LOCKED` record -
  a materially different, much narrower grant, and one this module's own brief explicitly lists
  as a possible access pattern (unlike every module before it).
- **Same-user IDOR at the URL level**: both routes take a real, already-existing id
  (`enrollment`/`student`) with no derived "my own" shortcut route. Changing the id in the URL
  is the exact attack the brief names by example (§7); it is refused by the service-layer
  ownership check above, not by obscuring the id space.

## 8. Endpoint design

**The brief's own §8's three candidate shapes, and its instruction to prefer the existing
`Result` identifier.** Resolved as `GET /report-cards/enrollments/{enrollment}/terms/{term}` -
reusing `enrollment_id` and `term_id`, the SAME pair `results.enrollment_id`/`results.term_id`
already key on, rather than a third invented "report card id" (nothing to key it to - see §3)
or `student_id`+`academic_session_id` (a student's identity alone never safely identifies a
placement, the reasoning every module since Module 06 already applies to its own references).
A mismatched enrollment/term pair needs no separate validation: `Result`'s own unique index
already makes it impossible for such a pair to have ever produced a row, so the natural "empty
result set" path already yields the correct 404 with zero extra code.

## 9. History listing

**The brief's own §9 central question**: is a history endpoint genuinely useful? **Yes** -
`GET /report-cards/students/{student}`, entry-pointed by `student_id` rather than
`enrollment_id` because its entire purpose is to cross a student's MULTIPLE enrollments (one
per academic session) - the one case in this project where keying off a person's id directly is
correct, since the endpoint only ever LISTS already-unambiguous (enrollment, term) pairs rather
than writing a new fact against a potentially-ambiguous one. No filters beyond `per_page` were
added: a student's entire history is bounded by (terms per session) × (sessions enrolled) - a
few dozen rows at the very most over a whole school career - so session/term filtering was
judged, per the brief's own "avoid unnecessary filtering infrastructure" instruction, not
genuinely needed yet.

## 10. Resource design

**The brief's own §10-11 instructions**: use the existing Resource architecture; do not invent
a new response format; expose only authoritative information. `ReportCardResource` nests
`EnrollmentResource` and `TermResource` WHOLESALE rather than flattening student/session/class/
section at its own top level - the identical "no second field list" discipline every resource
in this project already applies to its own nested records (§1.7), even though it departs
cosmetically from the brief's own flatter suggested sketch (`{student, academic, class, ...}`).
The brief's own instruction to "adapt this to the existing conventions" is read as explicit
license for this choice.

**No per-assessment breakdown** (§11's own conditional "if included"). Module 12's own
`ResultResource` and its migration docblock already establish the precedent this module
follows verbatim: `Score` remains the sole authoritative source of assessment-level detail, and
a client fetches it through `GET /scores` with the same filters, rather than either resource
duplicating it a second time.

## 11. Performance

**The brief's own §17.** Both endpoints are N+1-free, verified by dedicated tests that compare
query counts between a small and a large case rather than asserting a magic absolute number
(more robust: it directly detects SCALING, which is what an N+1 bug actually produces):

- **Single card**: one `Result` fetch (eager-loading `classSubject.subject`,
  `classSubject.schoolClass`), one `Enrollment` load, one `Term` load - flat regardless of how
  many subjects are on the card.
- **History list**: one query for the student's enrollment ids, one flat `Result` fetch (a
  handful of columns, no relations), then exactly two BULK hydration queries
  (`Enrollment::whereIn(...)`, `Term::whereIn(...)`) scoped to only the ids on the returned
  page - never one query per row.

The history list's pagination is done in PHP over an already-small, already-fetched
collection, not via a `GROUP BY` combined with `paginate()`'s own `COUNT`-for-pagination query.
This is the one place this module departs from "always call `Builder::paginate()`" (§1.8): a
student's whole report-card history is inherently small (bounded as in §9), so grouping and
paginating it in memory is simpler and more portable across this project's four supported
drivers than reasoning about `GROUP BY` interacting with count-for-pagination logic, for a
dataset this endpoint will never see at a scale where that distinction matters.

## 12. Summary calculations

**The brief's own §12, its most detailed caution: do not blindly average percentages if that
conflicts with the existing result methodology; do not invent an overall grade if none is
already defined.**

- **`overall_percentage`**: the plain, unweighted arithmetic mean of every included subject's
  own `percentage`. This introduces NO new weighting decision, because each subject's
  percentage is already normalized to 0-100 by Module 12 regardless of how many assessments or
  what weighting scheme produced it - averaging normalized values needs no further weighting.
  Every subject counts equally because this project's schema has no subject-credit or
  subject-weight concept at all (confirmed: neither `subjects` nor `class_subjects` carries
  one) - an unweighted mean is therefore the only default that adds no invented policy.
- **`average_grade_point`**: the mean of `grade_point` across included subjects, EXCLUDING
  `null`s (no grading scale configured, or the percentage fell in a gap) rather than treating
  them as zero - the identical missing-data discipline Module 12 itself established. `null`
  when no included subject has one at all.
- **`overall_grade`: deliberately NOT computed.** Feeding the averaged percentage back through
  `GradingService::calculate()` was considered and rejected: nothing in this project defines
  that a class-level grading scale is meant to interpret anything OTHER than one subject's own
  percentage, and inventing that meaning here - however tempting, since the calculation
  primitive already exists - would be exactly the "do not invent an overall grade the project
  does not already define" the brief explicitly warns against. This is a deliberate omission,
  not a missed opportunity.

## 13. Position/rank: explicitly not implemented

**The brief's own §13.** No ranking methodology, tie-handling rule, or scope (class vs.
section) is defined anywhere in this project's existing requirements - confirmed by audit: no
model, migration, or prior module discussion mentions one. Building a ranking algorithm here
would mean inventing school policy this module has no authority to set, and risks an expensive,
unbounded calculation (ranking requires comparing against every OTHER student's result in the
same scope) with no defined scope to bound it by. Documented here as an explicit, open future
decision - not a silent gap - for whichever future module receives an actual ranking
requirement to design against.

## 14. Comments: explicitly not implemented

**The brief's own §14.** No teacher/principal comment system, schema, or field exists anywhere
in this project (confirmed by a full-codebase grep for "comment"). No minimum schema was added
speculatively; this remains a documented future enhancement, exactly as the brief's own
instruction anticipates ("if comments do not exist... document as a future enhancement").

## 15. Attendance: explicitly not implemented

**The brief's own §15.** No attendance table or calculation exists anywhere in this project.
No placeholder structure was added to the report-card resource - the brief's own instruction is
to add one "only if the existing architecture clearly requires it," and nothing does.

## 16. Security review performed

- **IDOR**: `ReportCardAuthorizationTest` verifies a `STUDENT` cannot view or list another
  student's report card by changing the enrollment/student id in the URL, and that a
  neighboring, non-owned enrollment id is refused (403 or 404, never someone else's data).
- **Authorization bypass**: `STAFF` (including a teacher genuinely assigned to one of the
  card's own subjects) and a non-teaching staff member are both refused outright; a suspended
  account with a pre-suspension token is refused; a caller whose `report_cards.view` grant is
  revoked (at the role OR the individual level) is refused even while holding an unrelated
  permission (`results.view`).
- **Sensitive-field exposure**: verified no per-assessment breakdown, no account internals
  (email, password) leak through the nested `student`/`enrollment` records.
- **Read-only surface**: `POST`/`PUT`/`DELETE` against the single-card endpoint are all
  confirmed `405`.
- **Unpublished-result protection**: `INCOMPLETE`/`COMPILED`/`SUBMITTED`/`APPROVED` results are
  each verified, independently, to be invisible to every caller.

## 17. QA review

- **Focused tests**: 47 new tests across `ReportCardTest` (retrieval, workflow visibility for
  every non-finalized status, data accuracy against the authoritative `Result` rows, historical
  safety against later score/grading-scale changes, response structure, N+1), 
  `ReportCardAuthorizationTest` (permission matrix, `STAFF` exclusion, student self-access,
  three dedicated IDOR cases), and `ReportCardHistoryTest` (ordering, exclusion of
  unpublished-only terms, empty-list cases, pagination, envelope shape, N+1).
- **Full regression**: `php artisan test` - **1096 passed** (1049 pre-existing + 47 new), zero
  failures, run twice (before and after Pint's own auto-fixes).
- **Migration test**: `php artisan migrate:fresh --seed` - clean run, no errors, run three times
  across this module's development. Confirms no migration was needed: the seeder-only change
  integrates without a schema change.
- **HTTP smoke test**: `php artisan serve` against real Sanctum tokens (an admin, a student
  with a linked portal account, a second unrelated student, and a teaching staff member
  assigned to one of the card's own subjects), covering: login, a full report card with a mix
  of `PUBLISHED`, `LOCKED` and `SUBMITTED`-only subjects (confirming exactly the finalized two
  appear, with the correct `overall_percentage`/`average_grade_point`), student self-access,
  an IDOR attempt by a second student (403), an unauthorized attempt by an assigned teacher
  (403, structurally refused by the permission gate before the service is even reached), a
  history-listing IDOR attempt (403), a nonexistent enrollment/term/student (404 in each case),
  and a `PUT` against the single-card endpoint (405).
- **Performance**: both N+1 tests compare a small case against a substantially larger one (1 vs
  8 subjects; 1 vs 5 history rows) and assert the query count does not scale, catching the
  actual defect class an N+1 bug produces rather than asserting a guessed absolute number.

## 18. Deviations

**What differed from the brief's own suggested shape, and why:**

1. **The report card resource nests `EnrollmentResource`/`TermResource` wholesale**, rather
   than the brief's own flatter suggested sketch (`{student, academic, class, subjects,
   summary, comments}` as separate top-level keys). **Cause**: every resource in this project
   already refuses to maintain a second, divergent field list for a nested record it can
   instead delegate to that record's own resource. **Why this is correct**: the brief's own
   instruction to "adapt this to the existing API response/resource conventions" is exactly
   the license for this choice, and it means a future change to how a student or class is
   rendered needs no matching change here.
2. **History listing is entry-pointed by `student_id`, not `enrollment_id`**, where every
   result/score-bearing FACT in this project is deliberately keyed by `enrollment_id` to avoid
   student-identity ambiguity across sessions. **Cause**: the history endpoint's entire purpose
   is to cross MULTIPLE enrollments for one person; there is no single enrollment id to key it
   to. **Why this is correct**: it is a LISTING of already-unambiguous pairs, not a new fact
   being asserted against a potentially-ambiguous placement - the distinction the
   enrollment-not-student rule exists to protect never applies here.
3. **History-list pagination is done in PHP, not via `Builder::paginate()`** - a first in this
   project. **Cause**: a student's whole report-card history is small and bounded; avoiding a
   `GROUP BY` + count-for-pagination interaction across this project's four supported drivers
   was judged simpler and more robust than the marginal elegance of a single aggregate query,
   for a dataset that will never be large enough to need one.
4. **No `overall_grade`, no ranking, no comments, no attendance** - each explicitly offered as
   a candidate in the brief. **Cause**: none has an existing, authoritative definition anywhere
   in this project (§12-15). **Why this is correct**: inventing any of the four would mean
   this module setting school policy it has no data or requirement to set correctly, directly
   contradicting the brief's own repeated instruction not to invent what the project does not
   already define.
