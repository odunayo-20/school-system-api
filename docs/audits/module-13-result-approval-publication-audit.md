# Module 13 — Result Approval & Publication: Architecture Audit

Taking a compiled result through review to a final, immutable academic record:
COMPILED → SUBMITTED → APPROVED → PUBLISHED → LOCKED. Explicitly without Report Cards,
Promotion, a Result Checker, Attendance, Timetable, School Calendar, Notifications,
Announcements, File Management, Audit Logs, Dashboard/Statistics, or an API documentation
overhaul.

## 1. Audit findings (per the brief's own ten questions)

1. **Where result status currently lives**: `results.status`, a plain `string(20)` column
   (no DB-level `CHECK` constraint) cast to the `ResultStatus` PHP enum on the `Result` model.
2. **Whether Module 12 already created a lifecycle/status field**: yes, but only three cases -
   `INCOMPLETE`, `COMPILED`, `LOCKED`. `LOCKED` was never produced by Module 12; its own
   docblock explicitly reserved it "purely as the boundary Module 13 will use."
3. **Snapshot vs. dynamic recalculation**: a snapshot. `results.percentage`/`grade`/
   `grade_point`/`remark` are persisted columns, written once per compile by
   `ResultService::persist()`, never recomputed at read time.
4. **How completeness is determined**: `ResultService::calculateOutcome()` - `COMPILED` only
   when every `ACTIVE` `Assessment` for the class subject and term has a `Score` on file for
   the enrollment; `INCOMPLETE` otherwise.
5. **Teacher/class/subject authorization**: `ResultService::isAssignedTeacher()`/
   `activeTeachingAssignments()`, keyed on the `(class_subject_id, academic_session_id)` pair
   via an `ACTIVE` `TeacherAssignment` - `ScoreService`'s own established shape, reused
   unchanged by Module 12's `compile()`/`compileClassSubject()`.
6. **Roles/permissions**: `Role` enum (`SUPER_ADMIN`, `ADMIN`, `REGISTRAR`, `STAFF`,
   `STUDENT`); dot-namespaced `Permission` rows resolved through `Gate::forUser()->allows()`,
   wired by `AuthServiceProvider::registerPermissionAbilities()`, gated on routes via
   `permission:x.y` middleware (`EnsureUserHasPermission`), with a `Gate::before` `SUPER_ADMIN`
   bypass.
7. **Approval/publication metadata**: none existed. No column on `results`, and no
   precedent anywhere in this codebase for a "who performed this" foreign key - the closest
   analogues (`admissions.decided_at`, `teacher_assignments.ended_at`) record only *when*, never
   *who*.
8. **Existing audit mechanism**: none generic. There is no `audit_logs` table; Module 23 is
   explicitly reserved for that. The established per-row pattern for "when did the status
   change" is a nullable `*_at` timestamp column directly on the row itself (`decided_at`,
   `ended_at`) - this module extends that pattern with a matching `*_by` foreign key, the first
   in this codebase, because this is the first module whose own brief asks who acted.
9. **Whether any migration is necessary**: yes, for the eight workflow-metadata columns
   (§7). **No migration is needed for the new `ResultStatus` cases themselves** - the `status`
   column is a plain unconstrained string, so extending the PHP enum alone is sufficient; this
   was confirmed by reading the Module 12 migration before writing any code.
10. **Whether an existing service/controller can be extended**: yes, entirely.
    `ResultService`, `ResultController`, `ResultResource`, `ResultPermissionSeeder`, the
    `results` route group and `ResultFactory` are all extended in place. No new service,
    controller, resource, model, policy or migration-generating abstraction was created.

## 2. Files

### 2.1 Modified (no new service/controller/resource/model)

- `app/Enums/ResultStatus.php` - three new cases (`SUBMITTED`, `APPROVED`, `PUBLISHED`),
  `isRecompilable()`.
- `app/Models/Result.php` - `submittedBy()`/`approvedBy()`/`publishedBy()`/`lockedBy()`
  relations, `datetime` casts for the four new `_at` columns, updated docblock.
- `app/Services/Result/ResultService.php` - `submit()`/`approve()`/`publish()`/`lock()`,
  the shared `transition()`/`assertStatus()` helpers, and one changed line in `persist()`
  (§3 below).
- `app/Http/Controllers/Api/V1/Result/ResultController.php` - four new actions, extended
  `WITH`.
- `app/Http/Resources/ResultResource.php` - eight new fields, a small private `actor()`
  projection helper.
- `database/seeders/ResultPermissionSeeder.php` - four new permissions
  (`results.submit`/`.approve`/`.publish`/`.lock`) and their role grants.
- `routes/api.php` - four new `POST /results/{result}/{action}` routes.
- `database/factories/ResultFactory.php` - `submitted()`/`approved()`/`published()` states;
  `locked()` extended to accept an optional actor.
- `tests/Pest.php` - `submittedResult()`/`approvedResult()`/`publishedResult()`/
  `lockedResult()` helpers.
- `tests/Feature/Result/ResultIntegrityTest.php`,
  `tests/Feature/Result/ResultSecurityAndFilterTest.php` - two pre-existing Module 12
  assertions updated to match Module 13's own (correct) new behaviour; see §19.1-19.2.

### 2.2 Created

- `database/migrations/2026_10_07_090000_add_approval_workflow_to_results_table.php`
- `tests/Feature/Result/{ResultWorkflowTest,ResultWorkflowAuthorizationTest}.php`
- `docs/api/result-approval-publication.md`, this audit

### 2.3 Deliberately not created

- **No new `ResultWorkflowService`, `ResultApprovalService` or similar.** A workflow
  transition is not a distinct domain from compiling the result it transitions - it is the
  same record's next chapter, sharing `persist()`'s recompile guard, `ResultStatus`, and the
  teacher-scope check `compile()` already established. Splitting them into two services would
  duplicate the "what is this result's current status" question across two classes.
- **No `ResultWorkflowController`, no separate `WorkflowResultResource`.** Same reasoning: one
  resource renders a `Result` regardless of how it got there; one controller owns every action
  on `/results/{id}/...`, matching `TeacherAssignmentController`'s own `store`/`update`/`end`/
  `cancel` precedent on a single controller.
- **No `ResultWorkflowRequest`/`SubmitResultRequest`/etc.** None of the four transitions
  accepts a client body at all (§13), so there is nothing for a Form Request to validate beyond
  what route-model binding and the `permission:` middleware already do - see
  `AdmissionController::admit()`'s own identical zero-Form-Request shape for a transition that
  needs no reason.
- **No generic state-machine package or workflow engine.** Four transitions, each with exactly
  one valid prior state, checked by one twelve-line `assertStatus()` method. A general-purpose
  state machine would add a dependency and an abstraction layer to solve a problem this size
  does not have.
- **No new `Policy` class.** The existing `permission:` Gate mechanism (§1.6) already answers
  "may this user perform this ability"; a Policy class would duplicate it for zero benefit,
  the same reasoning every module since Module 01 has followed.
- **No `ResultSubmitted`/`ResultApproved`/etc. Events or Listeners.** Nothing in this module's
  scope currently needs to react to a transition; introducing an event bus for a currently
  empty set of listeners is exactly the "unnecessary Events/Listeners" the brief names by
  name. A future module that needs to react to a transition can dispatch from
  `ResultService::transition()` when it exists to react to.
- **No `results_status_history`/audit table.** Module 23 owns audit logging; this module's own
  four `(who, when)` pairs directly on `results` already answer every question this module's
  own brief asks (§11), without building the broader mechanism early.
- **No reject/reverse transition, and no columns for one.** See §3 and the API doc §3.5.
- **No public result-checking endpoint.** Reserved for Module 16, per the brief's own explicit
  instruction (§6, §19).

## 3. The central decision: extend the existing status column, no new table

The brief's own §1-3 asked whether Module 12 already has a lifecycle/status field, and
instructed against blindly implementing every transition if the existing architecture needs a
different representation. Module 12's `ResultStatus` already had exactly the shape needed:
`INCOMPLETE`/`COMPILED` for pre-workflow completeness, and an unused `LOCKED` reserved for
this exact module. **Resolution: extend the same enum, on the same column, with three new
cases** (`SUBMITTED`, `APPROVED`, `PUBLISHED`) rather than adding a second "workflow_status"
column alongside the existing "completeness_status" one.

Two columns tracking overlapping concerns - "is this result data-complete" and "how far
through approval has it gone" - would let them disagree (what does `INCOMPLETE` +
`PUBLISHED` even mean?) for no benefit; a result has exactly one true state at any moment.
`COMPILED` plays the role a separate `DRAFT` status would have played: nothing distinguishes
"just compiled, never submitted" from a hypothetical `DRAFT` case, so no second case was added
for it - see `ResultStatus`'s own docblock.

**No migration was needed for the enum extension itself.** The `results.status` column carries
no database-level `CHECK` constraint restricting its values (confirmed by re-reading the
Module 12 migration before writing any code) - it is validation-layer only, exactly like every
other status column in this project. Extending the PHP enum is the entire change.

## 4. The pipeline and its guard: recompilation stops at COMPILED

`ResultStatus::isRecompilable()` returns `true` for `INCOMPLETE`/`COMPILED` only, `false` for
`SUBMITTED`/`APPROVED`/`PUBLISHED`/`LOCKED`. `ResultService::persist()`'s single guard changed
from `$result->isLocked()` to `! $result->status->isRecompilable()` - a one-line change with a
large effect: **the historical-safety boundary Module 12 built only for `LOCKED` now applies
from `SUBMITTED` onward.** This is the module's one necessary, documented compatibility change
to Module 12's own code (the brief's own §18 explicitly permits "small compatibility changes
required for this module"), and it is the single mechanism behind every historical-safety
guarantee in §8 below - see the two pre-existing Module 12 tests updated to match in §19.1.

## 5. Submit, approve, publish, lock

Each transition is a thin, symmetrical method on `ResultService`, routed through one shared
`transition()` helper:

- **`submit(Result, User)`**: requires `COMPILED` (§3's `assertStatus()`); reuses
  `assertTeacherAuthorized()` unchanged, so a `STAFF` caller is scoped to their own
  `TeacherAssignment` exactly as `compile()` already is. Records `submitted_by`/`submitted_at`.
- **`approve(Result, User)`**: requires `SUBMITTED`. No teacher scope - only `ADMIN`/
  `SUPER_ADMIN` hold `results.approve` at all (§7). Records `approved_by`/`approved_at`.
- **`publish(Result, User)`**: requires `APPROVED`. Records `published_by`/`published_at`.
- **`lock(Result, User)`**: requires `PUBLISHED`. Terminal - records `locked_by`/`locked_at`;
  no further transition exists once `LOCKED`.

`assertStatus()` gives a submission-specific message when an `INCOMPLETE` result is submitted
(naming the actual obstacle - missing scores - rather than a generic "wrong status" message),
and a generic "this result is X, so it cannot be Y; it must be Z first" message for every other
out-of-order attempt, directly answering the brief's own §14 requirement for a consistent,
non-leaking error for an invalid transition.

**Not idempotent**, matching `AdmissionService::assertPending()` and
`TeacherAssignmentService::assertActive()`'s identical, already-established posture in this
codebase: repeating a transition is a 422, never a silent success. A workflow transition is a
one-shot event about a decision being made, not a reversible toggle.

## 6. Why there is no reject or reverse transition

The brief's own §3 explicitly asks whether a transition can be reversed, and whether reversal
needs a special permission. **Resolution: no reversal is built.** The brief's own workflow
diagram (§3) draws a single forward arrow at each stage, with no back-arrow anywhere. Building
one would require answering several genuinely new design questions with no requirement to
anchor them against: does rejecting a `SUBMITTED` result clear `submitted_by`/`submitted_at`,
or keep them as a record of the original attempt? Does an `APPROVED`→reject unwind
`approved_by` only, or also require a reason? None of this is specified, and guessing an
answer would be exactly the "elaborate override system" the brief's own §7 warns against
building without a genuine, current requirement. A result that needs correction today is
addressed outside this API - a defensible, honest gap, not silently glossed over; see the
residual risk noted in the final report.

## 7. Authorization and separation of duties

**Section 5's own central question** - can a submitter approve their own submission? -
was resolved **structurally, by role grant, not by a runtime check comparing users**:

- `results.submit`: `SUPER_ADMIN`, `ADMIN`, `REGISTRAR`, `STAFF` (scoped) - the identical set
  Module 12 already gives `results.compile`, since submitting is the natural continuation of
  compiling by the same actor.
- `results.approve`/`.publish`/`.lock`: `SUPER_ADMIN`, `ADMIN` **only**. Neither `STAFF` nor
  `REGISTRAR` holds any of the three.

Because `STAFF` and `REGISTRAR` can never hold `results.approve` at all, **a teacher or
registrar can never approve, publish or lock a result, whether or not they submitted it
themselves** - separation of duties falls out of the permission grants alone, with zero new
authorization code. A same-user "you cannot approve your own submission" runtime check was
considered and rejected: `ADMIN` is this project's one trusted day-to-day operator role, and no
module anywhere else in this codebase enforces a maker-checker pattern for `ADMIN` (the same
`ADMIN` who creates an admission also decides it; the same `ADMIN` who creates a staff record
also amends it) - inventing one here, for `ADMIN` alone, would be an unrequested complexity
this project's own conventions do not otherwise ask for, directly contradicting the brief's own
"do not invent a complex approval hierarchy" instruction (§5).

`REGISTRAR` and `STAFF` are narrowed out of approve/publish/lock for the same reason Module 11
narrowed `REGISTRAR` out of `grading_scales.*`: deciding a result is final enough to publish is
an academic-oversight POLICY judgment, not the "admissions, enrollment and student records"
clerical work `RoleSeeder` names for `REGISTRAR`, nor the teaching-adjacent mechanical act
compiling/submitting already is.

No additional class/subject scope was added to approve/publish/lock beyond the permission gate
itself: `ADMIN`/`SUPER_ADMIN` are unrestricted everywhere else in this codebase, and neither
`STAFF` nor `REGISTRAR` holds these permissions to need scoping in the first place.

## 8. Historical data safety

**Section 8's four concerns, each answered:**

- **Later score changes**: cannot silently corrupt a `SUBMITTED`-or-later result, because
  nothing recalculates a result except an explicit recompile, and recompile is refused past
  `COMPILED` (§4). Editing a `Score` after submission changes nothing about the already-written
  `Result` snapshot - pinned directly by
  `ResultWorkflowTest::'leaves a submitted/approved/published/locked result unaffected by a
  later change to its scores'`.
- **Later grading-scale changes** (Module 11): never read again for an existing result -
  `calculateOutcome()` only runs during compile, which is refused past `COMPILED`. Unaffected
  by this module structurally, not by any new check.
- **Later assessment-configuration changes** (Module 09): identical reasoning - a changed
  `max_score` or `weight` only matters the next time `calculateOutcome()` runs, which cannot
  happen once a result has entered the workflow.
- **Result compilation duplicating or overwriting an immutable record**: `persist()`'s upsert
  (locate-by-triple, `lockForUpdate()`, single row) already made duplication structurally
  impossible in Module 12; the `isRecompilable()` guard (§4) is what now makes *overwriting* a
  workflow-active result impossible too.

All four are consequences of Module 12's own snapshot architecture, deliberately preserved
rather than redesigned, extended by exactly the one guard change in §4.

## 9. Publication scope

**Section 10's own question** - individual result, student-for-a-term, class, subject, or
session/term set? **Resolution: individual result**, the smallest of the five options, and the
one Module 12's own architecture already operates at. A class-wide or session-wide publish
"set" was considered - a batch action mirroring `compileClassSubject()`'s own bulk shape - and
rejected for this module: nothing in the brief's own endpoint list (§13) asks for a bulk
publish, and Module 12's `compileClassSubject()` already gives a class teacher the equivalent
bulk *compile* workflow; layering a second, independent bulk operation onto approve/publish/
lock without a stated requirement would be exactly the "multiple publication systems" the
brief's own §10 says to avoid building. A future Result Checker or Report Card module reading
one result at a time (by enrollment, class subject and term - the exact triple `results`
already keys on) is fully supported by this design; nothing about individual-result publication
blocks a later batch UI built as repeated individual calls, or a genuinely new bulk endpoint
built if that requirement ever materializes.

## 10. Workflow metadata

**Section 11's own question**: which of `submitted_by/at`, `approved_by/at`, `published_by/at`,
`locked_by/at` are needed? **All eight** - the brief's own §4-7 each explicitly ask to "record
the submitting/approver/publication metadata," and there are exactly four transitions, so there
is no principled subset to omit. Each is a nullable `foreignId(...)->nullable()->constrained
('users')->nullOnDelete()` plus a nullable `timestamp`, matching this project's own established
`_at`-timestamp convention (`admissions.decided_at`, `teacher_assignments.ended_at`) for the
"when," extended with a "_by" foreign key for the "who" - the first such column in this
codebase, because this is the first module whose own brief asks who acted, not only when. This
is explicitly NOT the Module 23 Audit Logs module: it is eight columns on one row answering
"who did the four things this module's own workflow allows," not a generic, queryable record of
every change to every table.

## 11. Concurrency and data integrity

**Section 12's own scenarios, each addressed:**

- **Duplicate/simultaneous transition requests**: `transition()` wraps every write in
  `DB::transaction()` with `Result::query()->whereKey($id)->lockForUpdate()->firstOrFail()`,
  re-reading and re-locking the row inside the transaction rather than trusting the
  already-loaded, possibly-stale instance passed in. Two concurrent "approve" requests
  serialize on the lock; the second sees the already-`APPROVED` status once it acquires the
  lock and is refused by `assertStatus()` - pinned by
  `ResultWorkflowTest::'serializes two concurrent approvals of the same submitted result...'`
  and `'...does not allow a duplicate transition request to leave the result in an impossible
  state'`.
- **Simultaneous compilation and approval/lock**: the SAME `lockForUpdate()` mechanism
  `persist()` already uses (Module 12) serializes against `transition()`'s own lock, because
  both act on the identical row and the identical `results` table lock. Whichever acquires the
  lock first determines what the other sees: a compile that wins the race against a submit sees
  the pre-submission row and succeeds normally; a submit that wins sees `SUBMITTED` applied
  first, and a compile arriving after must now pass `isRecompilable()` - which refuses it. No
  interleaving produces a corrupted or duplicated row.
- **Simultaneous score changes and locking**: a score edit and a lock/publish act on different
  tables entirely (`scores` vs. `results`) with no shared lock; this is safe precisely because
  editing a score never reaches back to mutate an already-workflow-active `Result` at all (§8) -
  there is no race to protect against because there is no code path where the two writes
  interact.

No new infrastructure (queues, distributed locks, optimistic-concurrency version columns) was
added - the identical row-lock-inside-a-transaction technique every prior module in this
project already uses for its own unique-index/state-transition races.

## 12. API design and request validation

Four `POST /results/{result}/{submit|approve|publish|lock}` routes, the identical shape
`enrollments/{id}/withdraw` (Module 06) and `teacher-assignments/{id}/end|cancel` (Module 08)
already establish for a state transition - each gated on its OWN permission, never sharing
`results.compile`, matching those same two modules' own precedent of a separate permission per
real operation.

**No request body is accepted at all**, on any of the four - not even a `notes` field the way
`DecideTeacherAssignmentRequest`/`DecideAdmissionRequest` accept for `end`/`reject`/`withdraw`.
None of the brief's own four transitions (§4-7) asks for a client-supplied reason the way a
REJECTION would, and there is no reject transition in this module (§6) - so there was nothing
for such a field to attach to. Sending `{"status": "PUBLISHED"}`, or any other field, to any of
these endpoints is simply never read by the controller - directly satisfying the brief's own
§14 "a publish request should not allow the client to submit status and bypass the workflow,"
pinned by `ResultWorkflowTest::'never accepts a client-supplied status or workflow metadata on
a transition endpoint'`.

Errors follow this project's existing, unmodified conventions: `401` unauthenticated, `403`
forbidden (missing permission, or `STAFF` outside their `TeacherAssignment` scope), `404` for a
result id that does not exist, `422` for every invalid-transition/incomplete-result case, each
via `BusinessRuleViolation`'s existing global handler - zero new exception classes, zero new
rendering logic.

## 13. Database design

One migration, `2026_10_07_090000_add_approval_workflow_to_results_table.php`, adding eight
nullable columns to the existing `results` table - no new table (§3), no `school_id`/
`tenant_id`/`branch_id`/`organization_id` (this remains a single-school system), no new
indexes (nothing in this module's own query surface needs one beyond `results.status`'s
already-existing index from Module 12), and no `CHECK` constraint on the status column, matching
every status column elsewhere in this project. Each `"_by"` column is
`nullable()->constrained('users')->nullOnDelete()`, matching `admissions.student_id`'s own
established reasoning: the historical fact that a transition happened must outlive the specific
user account that performed it.

## 14. Testing

- **Focused tests**: 40 new tests across `ResultWorkflowTest` (every valid transition, every
  invalid/out-of-order transition, repeated transitions, locked-result immutability, workflow
  metadata correctness at every step, historical-data safety against a later score edit,
  concurrency) and `ResultWorkflowAuthorizationTest` (the full permission matrix, teacher
  scope on submit, IDOR by result id, separation of duties for `STAFF` and `REGISTRAR`, 404 vs.
  403 for a nonexistent id).
- **Two pre-existing Module 12 tests updated**, not to weaken them but because Module 13
  correctly changes the behaviour they pinned - see §19.1-19.2.
- **Full regression**: `php artisan test` - **1049 passed** (937 pre-Module-12 + 72 Module 12 +
  40 Module 13), zero failures, run twice (before and after Pint's own auto-fixes, to confirm
  they changed nothing behavioural).
- **Migration test**: `php artisan migrate:fresh --seed` - clean run, no errors, run three times
  across this module's development (mid-development, pre-smoke-test, and as the final
  pre-commit check).
- **HTTP smoke test**: see §15.

## 15. HTTP smoke test

`php artisan serve` against real Sanctum tokens (an `ADMIN`, a `REGISTRAR`, an authorized
`TEACHING` staff member with a matching `TeacherAssignment`, an unauthorized `TEACHING` staff
member with none, and a `STUDENT`), covering every item the brief's own §17 lists by number:

1. Login (all five accounts). 2. `GET /results/{id}` on a freshly compiled result - `200`.
3. `POST .../submit` on a `COMPILED` result - `200`, `SUBMITTED`. 4. An invalid submission: a
   second assessment added and left unscored, forcing `INCOMPLETE`, then `POST .../submit` on
   the original (now-stale) result id - `422` naming the missing-scores reason specifically.
5. `POST .../approve` by `ADMIN` on the (re-completed, re-submitted) result - `200`,
   `APPROVED`. 6. Unauthorized approval: attempted by the unassigned teacher (`403`) AND by the
   `REGISTRAR` (`403`) - confirming separation of duties holds even for a role that CAN submit.
7. `POST .../publish` by `ADMIN` - `200`, `PUBLISHED`. 8. Publication before approval: attempted
   against a freshly recompiled (`COMPILED`, never submitted) result by the unauthorized
   teacher - `403`. 9. `POST .../lock` by `ADMIN` - `200`, `LOCKED`. 10. Mutation of the locked
   result: a recompile attempt (`422`, naming `LOCKED`), a re-submit attempt (`422`), a `PUT`
   (`405`), a `DELETE` (`405`). 11. Every response verified against the project's
   `{ "data": ..., "message": ... }` envelope, with `submitted_by`/`approved_by`/`published_by`/
   `locked_by` correctly rendered as `{id, name}` and their paired `_at` timestamps populated
   exactly at the step each transition occurred, `null` before. 12. Authentication (`401` with
   no token) and permission failures (`403` for a `STUDENT` attempting `submit`) both verified
   directly.

Every case behaved exactly as designed on the first run.

## 16. Quality gates

- `php artisan migrate:fresh --seed` - clean.
- `php artisan test` - **1049 passed**, 0 failed, 0 skipped.
- `./vendor/bin/pint --test` - clean (after one auto-fix pass for import ordering and a fully-
  qualified-type-hint normalization, re-verified with a full regression re-run afterward).

## 17. Residual risks

- **No reject/reverse transition exists** (§6). A result submitted in error today has no API
  path back to `COMPILED` short of direct database intervention. This is a deliberate,
  documented gap, not an oversight - building one without a stated requirement for its exact
  semantics (what happens to prior metadata, does it need a reason) would have been the
  "elaborate override system" the brief's own §7 warns against. A future module can add it once
  a genuine requirement defines what "reject" should mean.
- **No bulk approve/publish/lock.** An administrator approving an entire class's submitted
  results today calls `POST .../approve` once per result. This mirrors `compile()`'s own
  single-vs-bulk split (Module 12 already offers `compileClassSubject()` for the compile side),
  but no equivalent bulk exists for approve/publish/lock, because nothing in this module's brief
  asked for one (§9) - a real usability gap once a school has many results to approve at once,
  left for the same reasoning §9 gives: no stated requirement to build against yet.
- **A `LOCKED` result has no administrative correction path at all.** By design (§6, §7 of the
  brief) - a genuine limitation if a locked result is later found to be wrong, deliberately left
  unaddressed until a real correction mechanism is specified.

## 18. Deviations

**What differed from the brief's own suggestion, and why:**

1. **No `DRAFT` status was added**, where the brief's own workflow names one explicitly.
   **Cause**: Module 12's existing `COMPILED` already means exactly what `DRAFT` would mean
   here - a complete, compiled result awaiting submission - and no case in this project's
   `ResultStatus` distinguishes "just compiled" from "compiled and not yet submitted" in any
   way that would justify a second name for the same fact. **Why this is correct**: one status
   column, one true state at a time - see §3.
2. **No reject/reverse transition**, where the brief's own §3 asked me to determine whether one
   is needed. **Cause**: no forward-only workflow that this brief specifies actually requires
   one, and the brief's own diagram draws no back-arrow. **Why this is correct**: per §6 and
   the brief's own "do not invent an elaborate override system" instruction (§7) - building
   reversal semantics with no requirement to anchor them against would be exactly the
   speculative architecture this project's conventions caution against everywhere else.
3. **Separation of duties enforced by role grant, not a same-user runtime check**, where the
   brief's own §5 left the mechanism open. **Cause**: no maker-checker pattern exists anywhere
   else in this codebase for `ADMIN`, this project's one trusted operator role. **Why this is
   correct**: per §7 above - it is simpler, fully sufficient for the stated requirement (a
   teacher/registrar can never approve their own or anyone's submission), and consistent with
   how every other module in this project treats `ADMIN`.
4. **`REGISTRAR` holds `submit` but not `approve`/`publish`/`lock`**, a split within a single
   role that does not exactly mirror any single prior module's own REGISTRAR grant. **Cause**:
   submitting is mechanical execution (Module 12's own reasoning for giving REGISTRAR
   `results.compile`); approving/publishing is an academic-oversight policy decision (Module
   11's own reasoning for narrowing REGISTRAR out of `grading_scales.*`). **Why this is
   correct**: the split reuses two ALREADY-established precedents rather than inventing a
   third, applied to the two different kinds of action this module actually has.
