# Module 11 — Grading: Architecture Audit

How raw assessment scores are interpreted as academic grades - percentage, grade, grade point,
remark. Explicitly without result compilation, weighted totals, report cards or promotion, all
reserved for Module 12 and later.

## 1. What was already in the codebase

No grading/result code existed anywhere. The audit focused on four things this module's design
turns on:

- **`Assessment.max_score` and `weight`** (Module 09) are decimal(6,2)/decimal(5,2) columns,
  confirming the existing precedent for storing a 0-100-shaped percentage value: exact decimal
  storage, not float, and a model cast of `decimal:2` for driver-independent presentation.
- **`Score.score`** (Module 10) is a raw mark, `decimal(6,2)`, with `max_score` deliberately
  never duplicated onto the scores table - `score_percentage` is computed at read time in
  `ScoreResource`, guarded against a zero denominator. This is the exact percentage this
  module's `calculate` operation is designed to receive as input.
- **`TracksSingleActiveRecord`** (used unchanged by `School`, `AcademicSession`, `Term`) derives
  a nullable `active_marker` column purely from a model's `status` attribute via a `saving`
  hook; the SCOPING behaviour of "at most one active record" comes entirely from the table's
  own unique index, not from the trait. Re-reading it confirmed it is directly reusable for a
  scoped singleton with zero modification - see §3.
- **`ValidatesCatalogRecord`** (Module 02, already reused verbatim by `Subject` and
  `AssessmentType`) provides scoped name/code uniqueness, `sort_order`, and a `CatalogStatus`
  rule, confirming the established shape for "a catalogue entry scoped to a parent."

## 2. Files

### 2.1 Created

- `database/migrations/2026_10_05_090000_create_grading_scales_table.php`,
  `2026_10_05_090001_create_grading_scale_items_table.php`
- `app/Models/GradingScale.php`, `app/Models/GradingScaleItem.php`
- `app/Services/Grading/GradingService.php`
- `app/Http/Requests/Grading/{Store,Update,Calculate}GradingScaleRequest.php`,
  `GradingScaleListRequest.php`
- `app/Http/Requests/Grading/Concerns/ValidatesGradingScaleRecord.php`
- `app/Http/Resources/GradingScaleResource.php`, `app/Http/Resources/GradingScaleItemResource.php`
- `app/Http/Controllers/Api/V1/Grading/GradingScaleController.php`
- `database/seeders/GradingPermissionSeeder.php`
- `database/factories/GradingScaleFactory.php`, `database/factories/GradingScaleItemFactory.php`
- `tests/Feature/Grading/{GradingScaleManagementTest,GradingScaleSecurityAndFilterTest,
  GradingCalculationTest,GradingScaleIntegrityTest}.php`
- `docs/api/grading.md`, this audit

### 2.2 Modified

- `routes/api.php` - new `grading-scales` route group, including
  `POST /grading-scales/{id}/calculate`.
- `database/seeders/DatabaseSeeder.php` - `GradingPermissionSeeder::class` added after
  `ScorePermissionSeeder::class`.
- `tests/TestCase.php` - same seeder added to the baseline `$this->seed([...])` list.
- `tests/Pest.php` - imports, `standardGradingBands()`, `gradingScaleCreatePayload()`,
  `gradingScaleUpdatePayload()`, `configuredGradingScale()`.

### 2.3 Kept unchanged

Every file from Modules 01-10. `TracksSingleActiveRecord`, `ValidatesCatalogRecord`,
`ClassLevel`, `ListRequest` and `ApiResponse` were **read fresh in this session** before reuse,
not assumed from memory.

### 2.4 Deliberately not created

- **No `GradingEngine`, `GradingRepository`, `GradingManager`, `GradingHelper`,
  `GradingTransformer`, `GradingDTO`, `GradingInterface`.** The calculation operation is nine
  lines on `GradingService` (a `first()` over an already-loaded collection with a predicate
  method on the model); nothing about this module's actual size justifies a dedicated engine
  abstraction.
- **No separate `GradingScaleItemService` or `/grading-scale-items` endpoints.** An item has no
  meaning independent of the scale that owns it, and overlap/duplicate-grade validation is
  inherently a whole-set concern - see §5. Items are managed entirely through the
  `GradingScale` resource (create with nested `items`, full-replace on update), the smallest
  architecture the brief's own §20 explicitly permits.
- **No hardcoded grade thresholds anywhere in the codebase.** Every grade, grade point, remark
  and boundary is a row an administrator configures via the API; nothing in `GradingService`
  or its controller compares a percentage against a literal number.
- **No nullable "whole-school default" scope, and no polymorphic/generic scope-assignment
  table.** See §3 for why `class_level_id` is required, not nullable.
- **No grading-scale versioning or result-snapshot system.** See §9 for why historical safety
  is deliberately left to Module 12's own compile-time snapshot, not built preemptively here.
- **No new `Observer` class.** `GradingScale` reuses `TracksSingleActiveRecord`'s existing
  `static::saving()` hook unchanged; nothing new was registered.
- **No duplicate `ClassLevel`, `Score`, or `Assessment` model or logic.** `GradingService`
  references `ClassLevel` directly and takes a plain `float $percentage` as its calculation
  input - it does not read a `Score` or an `Assessment` at all, keeping the module fully
  decoupled from how a percentage was arrived at (see §0 of the API doc).

## 3. The central decision: class-level scope, not a nullable default

**Section 15's own question** - where does a grading scale apply? - was resolved by first
re-confirming exactly how `TracksSingleActiveRecord` achieves "at most one active record": the
trait derives `active_marker` from `status`, and the actual SCOPING guarantee comes entirely
from the table's own unique index (a plain `unique(active_marker)` for a school-wide singleton
in `School`/`AcademicSession`/`Term`, or a composite one for a scoped singleton, the technique
Module 08 introduced for `TeacherAssignment`).

A **nullable** `class_level_id` (representing "whole-school default, no specific level") was
the first design considered, to satisfy §15's "entire school" option directly. It was rejected
once the actual constraint was worked through: standard SQL does not treat two `NULL` values as
equal for unique-index purposes (SQLite, MySQL and PostgreSQL all permit multiple `NULL`s in a
unique column; SQL Server is the sole, inconsistent exception, treating them as equal). Since
`unique(class_level_id, active_marker)` would need BOTH columns to compare equal to register a
conflict, two different "default" scales (`class_level_id IS NULL`, `active_marker = 1`) would
never collide on three of this project's four configured drivers - silently defeating the very
invariant the index exists to guarantee, and inconsistently across drivers besides.

**Resolution: `class_level_id` is `NOT NULL`, required at creation, immutable thereafter.**
Every scoping column in every composite singleton this project already has (`TeacherAssignment`'s
`class_subject_id`+`academic_session_id`) is itself `NOT NULL` - only the trailing
`active_marker` column carries the nullable-for-distinctness trick. This keeps
`GradingScale` consistent with that established shape exactly, with zero special-casing, and
directly matches the brief's own worked example (Primary's A/B/C/D differing from another
level's A1/B2/B3/C4...). A school wanting one uniform scheme across levels defines matching
bands under each level's own scale - explicit and portable, rather than an implicit fallback
only some database engines would actually enforce.

## 4. `TracksSingleActiveRecord`, reused unchanged

`GradingScale` uses the trait exactly as `School`/`AcademicSession`/`Term` do:
`activeStatusEnum()` returns `CatalogStatus::class`, and the migration's
`unique(['class_level_id', 'active_marker'])` index supplies the scoping the trait itself has
no opinion about. No modification to the trait was needed or made - confirmed directly by
reading its source before writing a single line of `GradingScale`, per this module's own audit
discipline.

Because `CatalogStatus` is a **freely reversible** three-state enum (unlike
`TeacherAssignmentStatus`'s terminal two-state shape, the only prior consumer of a SCOPED
singleton), the marker must be re-derived on every ordinary `PUT`, not only at fixed workflow
transitions - which the trait already does unconditionally on every `saving` event, regardless
of which code path changed `status`. This is precisely why the trait needed no scoped variant
of its own: its derivation logic was already general enough.

## 5. Grading scale items: validated and rewritten as one group

**Section 9's core requirement** - prevent overlapping ranges - is answered by
`ValidatesGradingScaleRecord::addItemCoherenceValidation()`, a `withValidator()` hook (not a
per-field rule) that runs a pairwise inclusive-interval overlap test
(`min <= otherMax && otherMin <= max`) across the WHOLE submitted `items` array, plus a
duplicate-grade check and a per-band `min <= max` check. This cannot be expressed as a
declarative `Rule::exists()`/`Rule::unique()` closure the way every prior module's FK checks
are, because it compares EVERY band against every OTHER band in the same request, not a single
field against the database.

**Boundaries are inclusive at both ends** (§8's explicit question): `min_percentage <=
percentage <= max_percentage`. Two adjacent, non-overlapping bands are therefore written as
`60.00`–`69.99` next to `70.00`–`100.00`; the seemingly-natural `60`–`70` next to `70`–`100` is
REFUSED as an overlap, since both would cover `70.00`. `69.99` vs `70.00` vs `70.01` is pinned
directly by `GradingCalculationTest::'it distinguishes 69.99 from 70.00 across the same
boundary'`.

**Gaps are allowed, not rejected** (§10's explicit question). A school may legitimately leave a
range undefined - nothing in the domain requires every percentage from 0-100 to resolve to a
grade (an absence code, an incomplete assessment, or simply a school that has not yet decided
what happens below a floor are all legitimate reasons). Silently defaulting an uncovered
percentage to the nearest band, or to some hardcoded fallback, was explicitly rejected by the
brief's own §10 ("Do not silently assign an undefined grade"). The safety net is instead at
CALCULATION time: `GradingService::calculate()` returns `null` when no band covers the
percentage, and the controller renders `grade`/`grade_point`/`remark`/`matched_band` all as
`null` with a `200`, not a guess and not an error.

**Items always replace, never diff.** `GradingService::create()`/`update()` both
delete-and-recreate the full item set inside one transaction. This is the simplest correct
implementation of "PUT is a whole-record write" applied to a one-to-many relationship, and it
is what makes the overlap/duplicate-grade validation tractable in the first place: the
Form Request always validates the COMPLETE final state of a scale's bands, never a partial
diff against rows already in the database.

## 6. Percentage → grade calculation

`POST /grading-scales/{id}/calculate` takes exactly one input, `percentage`, and returns
`percentage`, `grade`, `grade_point`, `remark`, and the full `matched_band`. Nothing else
influences the answer - `GradingCalculationTest::'it
cannot be bypassed with a client-supplied grade or grade point'` sends `grade`/`grade_point`
alongside `percentage` and confirms both are ignored, directly answering §12's and §19's
"do not trust client-supplied calculated values" requirement.

The operation is read-only and side-effect-free: it loads the scale's own `items` (a single
query, or none if already eager-loaded) and runs a plain `Collection::first()` with a predicate
method on `GradingScaleItem` (`covers(float $percentage): bool`). No `GradingEngine` class was
introduced - see §2.4.

## 7. Assessment weights: acknowledged, not consumed

**Section 13's audit question** - does Module 09 have weights, and how should grading interact
with them? Confirmed: `assessments.weight` (nullable decimal) exists, representing "this
assessment's share of the class subject's overall grade for the term" - explicitly NOT
constrained to sum to 100 across a class subject's assessments (Module 09's own deliberate
design). This module does not read `weight` at all. `calculate()` takes a single percentage,
already normalized by whatever caller derived it (Module 10's own `Score::score /
Assessment::max_score`, computed in `ScoreResource`). Combining several assessments' weighted
percentages into one subject grade is squarely "Weighted Result Contribution" - the brief's own
explicit boundary (§13-14) placing that calculation in Result Compilation, not here. This
module provides the raw "percentage → grade" primitive Module 12 will call once per assessment,
or once per already-computed weighted total; it does not know or care which.

## 8. Database design

- `grading_scales`: `class_level_id` (`restrictOnDelete`), `name`/`code` (unique per class
  level), `sort_order`, `status`, `active_marker` - column-for-column matching the
  `TracksSingleActiveRecord`-based tables already in this project, plus the scoped-singleton
  composite index Module 08 introduced.
- `grading_scale_items`: `grading_scale_id` (**`cascadeOnDelete`**, the one deliberate
  departure from every other foreign key introduced since Module 05 - see the migration's own
  docblock: an item has no meaning independent of its scale, unlike every academic-history
  anchor those other foreign keys protect), `grade` (unique per scale), `min_percentage`/
  `max_percentage` (`decimal(5,2)`, matching `assessments.weight`'s own precedent),
  `grade_point` (nullable `decimal(4,2)`), `remark` (nullable).
- No `sort_order` on `grading_scale_items` - a band's percentage range already gives it an
  intrinsic, unambiguous display order (`items()` relation ordered `min_percentage` descending);
  a second, independently-editable ordering field would be redundant.
- No `CHECK` constraint for `min_percentage <= max_percentage` or the overlap invariant -
  SQLite (the test driver) enforces `CHECK` inconsistently across `ALTER` paths, the identical
  reasoning the `assessments` migration already gives for skipping one on `max_score`; both are
  application-layer guarantees instead, consistent with existing precedent.
- Indexes: `unique(class_level_id, name)`, `unique(class_level_id, code)`,
  `unique(class_level_id, active_marker)` on `grading_scales`; `unique(grading_scale_id, grade)`
  and a composite `(grading_scale_id, min_percentage, max_percentage)` on
  `grading_scale_items` for the one query this module actually runs ("which band covers this
  percentage, for this scale").

## 9. Historical safety

**Section 23's central question**, answered without building versioning: could changing a
scale's bands retroactively change the meaning of an already-published result? Today, nothing
references a `GradingScale` except this module itself, so the question has no live consequence
yet - but the FOUNDATION is laid for when it does:

- **No delete endpoint on `GradingScale`** (§1.1 of the API doc) - the single largest
  historical-safety risk (an entire scale vanishing out from under a reference) is foreclosed
  outright, at zero implementation cost, by simply not building `destroy()`.
- **Items remain freely editable via `PUT`** while a scale is in use. This was a deliberate
  choice, not an oversight: building immutability or versioning now, with no actual consumer to
  test it against, would be exactly the "full versioning system without evidence it is
  required" §23 itself cautions against.
- **The correct historical-safety mechanism belongs to Module 12, not this one**: when a future
  Result Compilation module computes a student's grade, it should copy the RESOLVED
  `grade`/`grade_point`/`remark` onto its own result row at compile time (a snapshot of the
  answer), not store a live reference to the `GradingScaleItem` that produced it. A later change
  to this module's bands would then affect only results compiled AFTER the change - exactly the
  behaviour §23's worked example (70-100→A becoming 75-100→A) requires, achieved entirely by
  Module 12's own design choice, with no change needed here. This expectation is recorded
  explicitly as "what this module owes the next one" so Module 12 does not have to rediscover
  it.

## 10. Authorization

**Section 21's own questions, answered:**

- **View**: `SUPER_ADMIN`, `ADMIN`, `REGISTRAR`.
- **Create/update**: `SUPER_ADMIN`, `ADMIN` only.
- **Activate/deactivate**: the ordinary `PUT`'s `status` field - no separate workflow endpoint,
  matching `Subject`/`ClassSubject`'s own freely-reversible `CatalogStatus` toggle precedent,
  not `Enrollment`/`Admission`/`TeacherAssignment`'s terminal-workflow one (a grading scale
  being turned off is not a fact about a period that closed, the same distinction Module 09
  already drew for `Assessment.status`).
- **Manage ranges**: identical to create/update - ranges are never independently addressable
  (§5), so there is no separate "manage ranges" permission to grant.
- **Calculation**: gated on `.view`, not a new permission - a read-only preview of a scale's
  own configuration is closer to reading than to writing.
- **`REGISTRAR` narrowed to view-only**, a deliberate departure from Module 09's
  `assessments.*` split (full CRUD minus delete) and instead matching Module 08's
  `teacher_assignments.*` split (view-only): grade boundaries are school ACADEMIC POLICY,
  further still from `RoleSeeder`'s own stated registrar duties than assessment configuration
  already was.
- **`STAFF` holds nothing at all**, including teaching staff who hold `scores.*` (Module 10).
  The brief's own explicit instruction (§21): entering a score never implies a say over what
  that score means. Unlike Module 10, there is no scoped exception to carve out here - grading
  policy is school-wide, not a per-class-subject teaching duty, so no "assigned teacher" scope
  would ever apply.
- **`STUDENT` holds nothing.**

## 11. Security review performed

- **Authorization bypass**: `GradingScaleSecurityAndFilterTest` verifies `STAFF` (including a
  real `TEACHING` staff member) and `STUDENT` cannot create, and `REGISTRAR` cannot
  create/update despite holding `view`.
- **IDOR**: one scale cannot be read or amended through another's id.
- **Range manipulation**: overlapping ranges, a shared-boundary "overlap" (`[60,70]` vs
  `[70,100]`), a duplicate grade, an out-of-bounds percentage, and `min > max` are all refused
  with `422` and nothing is persisted (`GradingScale::query()->count()` asserted `0` after each).
- **Mass assignment**: `class_level_id` has no key in `UpdateGradingScaleRequest` at all;
  `status`/`active_marker` injection attempts on create are verified inert (a new scale is
  always `ACTIVE` with `active_marker = true`, regardless of what a client sends).
- **Historical corruption**: `class_level_id` cannot be repointed through the amend endpoint,
  and no delete endpoint exists at all - the two changes that could most directly corrupt a
  future historical interpretation are both structurally unreachable.
- **Student access**: verified explicitly against both list/create and the calculation
  endpoint.

## 12. QA review

- **Focused tests**: 70 new tests across `GradingScaleManagementTest` (create/read/amend/
  validation/lifecycle), `GradingScaleSecurityAndFilterTest` (auth matrix, filters, IDOR, mass
  assignment), `GradingCalculationTest` (every boundary case the brief names by name: `0%`,
  `100%`, `69.99%`, `70%`, `70.01%`, a decimal percentage, a gap), `GradingScaleIntegrityTest`
  (the scoped-singleton race, FK `restrictOnDelete` on `class_level_id`, and the one
  intentional `cascadeOnDelete` in this module).
- **Full regression**: `php artisan test` - **937 passed** (868 pre-existing + 69 new), zero
  failures.
- **Migration test**: `php artisan migrate:fresh --seed` - clean run, no errors, on every
  attempt across this module's development.
- **HTTP smoke tests**: `php artisan serve` against real Sanctum tokens (super admin, a real
  `TEACHING` staff member, a registrar), covering: authentication, an empty list, scale
  creation with three bands, a full-replace update revising the A/F boundary from 70 to 75, an
  overlapping-range rejection, an out-of-bounds boundary rejection, an unauthorized teacher
  creation attempt (`403`), a calculation at 85%, boundary calculations at exactly 75 and
  74.99 confirming the revised cutoff took effect, a decimal calculation at 87.5%, a
  registrar's view-but-not-create access, and a pre-existing Module 07 endpoint (`GET
  /subjects`) confirmed still functioning.
- **Performance**: `DB::enableQueryLog()` around a rendered page of 6 scales showed **3 flat,
  batched queries** (count, page, `class_levels` batch) with no `items` load at all (by
  design - the list endpoint omits bands); a `show()` of one scale with 5 bands showed **3
  queries** (the scale, its class level, its items) - zero N+1 in either case.
- **Boundary/decimal/invalid-configuration tests**: all pinned directly, per §31's own
  exhaustive list - `0%`, `100%`, `69.99%`, `70%`, `70.01%`, a decimal score-derived
  percentage (`87.5`), decimal grade-point boundaries, overlapping ranges (both a clear overlap
  and the single-shared-boundary-point case), a gap, a duplicate grade, an inactive scale
  (excluded via `active_only`), an invalid/nonexistent scale id (`404`), and a percentage
  falling in a gap (`grade: null`, not an error).

## 13. Deviations

**What differed from the brief's own suggestion, and why:**

1. **`class_level_id` is required, not nullable**, where the brief's own §15 listed "entire
   school" among the candidate scopes without ruling out a default/fallback shape. **Cause**:
   this project's existing single-active-record technique
   (`TracksSingleActiveRecord`/`active_marker` plus a unique index) depends on every SCOPING
   column being `NOT NULL`; standard SQL's NULL-is-distinct behaviour (SQL Server the sole,
   inconsistent exception) would make a nullable scope's own singleton constraint silently
   unenforceable on three of this project's four configured drivers. **Why this is correct**:
   it keeps the new table byte-for-byte consistent with the established pattern, needs no
   driver-specific workaround, and still lets a school achieve uniform grading across levels by
   defining matching bands per level - an explicit, portable choice rather than an implicit,
   partially-broken one.

2. **No dedicated `/grading-scale-items` resource**, where the brief's own §6 and §20 floated
   nested or dedicated endpoints as live options. **Cause**: overlap and duplicate-grade
   validation are inherently whole-set operations - adding or amending one band cannot be
   validated correctly in isolation from its siblings without either re-fetching and
   re-checking the whole set on every single-item write (equivalent complexity to a full
   replace, with an added race window between item-level requests) or accepting weaker
   validation. **Why this is correct**: managing the full `items` array through the
   `GradingScale` resource itself makes "validate everything together, write everything
   together" the natural, race-free shape, and is explicitly the smallest option the brief's
   own §20 permits ("management through the grading-scale resource").
