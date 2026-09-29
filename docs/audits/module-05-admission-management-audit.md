# Module 05 — Admission Management: Architecture Audit

Status: **implemented**
Scope: the school's decision record for one applicant, for one target academic session.

---

## 1. What was already in the codebase

Verified against the live schema and the filesystem before any code was written:

| Thing | State found | Consequence |
|---|---|---|
| `admissions` table, model, service, controller, requests, resource, tests | none | Greenfield. |
| Any `Applicant`/`Application` model | none | No competing domain to reconcile. |
| `admission_number`, `application_number`, `registration_number` | referenced only in comments | Every hit was a forward-looking note explaining why the *student* table does **not** own one - see `students` migration and Module 04 audit §3.3. None was a seeded column or permission. |
| `Student → Admission → Enrollment` | stated repeatedly | Module 04's own architecture already commits to this lifecycle; this module fulfils rather than invents it. |
| `RoleSeeder`'s description of `REGISTRAR` | *"Handles admissions, enrollment and student records"* | The strongest single piece of evidence for the permission grants in §7. |
| `StudentService::create()` | exists, transactional, derives `student_number` from the primary key via a UUID reservation | Reused directly by `AdmissionService::admit()`. Not re-implemented. |
| `StudentService::update()`'s terminal-freeze rule | a `GRADUATED`/`WITHDRAWN` student refuses **every** amend, including a status no-op | This is the reason re-admitting a previously departed pupil is out of scope - see §6. |
| Module 02's `AcademicSessionStatus` (`UPCOMING`/`ACTIVE`/`COMPLETED`) and `CatalogStatus` (`ACTIVE`/`INACTIVE`/`ARCHIVED`) | exist, no `RETIRED` session state | Reused as-is for the "must not target a completed session" and "must be a selectable class level" rules - no second status system introduced. |
| `restrictOnDelete` convention (`terms.academic_session_id`, `classes.class_level_id`) | established | Followed for `admissions.academic_session_id` and `admissions.entry_class_level_id`. |

Nothing in the audit contradicted the brief's assumed lifecycle shape; the one place the brief's
suggested defaults were **not** followed is the `APPLIED`/`UNDER_REVIEW` split, addressed in §4.

---

## 2. Files

### 2.1 Created

| File | Why |
|---|---|
| `database/migrations/2026_09_29_180000_create_admissions_table.php` | The one new table. |
| `app/Enums/AdmissionStatus.php` | `PENDING`/`ADMITTED`/`REJECTED`/`WITHDRAWN`. |
| `app/Models/Admission.php` | Identity snapshot, lifecycle, three `belongsTo` relations. |
| `app/Services/Admission/AdmissionService.php` | Every business rule: derivation, terminal freeze, the three transitions. |
| `app/Http/Requests/Admission/{Store,Update,List,Decide}AdmissionRequest.php` | One per real endpoint shape. |
| `app/Http/Requests/Admission/Concerns/{ValidatesAdmissionRecord,ValidatesAdmissionFilters}.php` | Shared rule sets, mirroring Module 04's `Concerns` split. |
| `app/Http/Resources/AdmissionResource.php` | Explicit field list; nests `AcademicSessionResource`, `ClassLevelResource`, `StudentResource`. |
| `app/Http/Controllers/Api/V1/Admission/AdmissionController.php` | Seven actions, no `destroy()`. |
| `database/seeders/AdmissionPermissionSeeder.php` | Six permissions, `syncWithoutDetaching()`. |
| `database/factories/AdmissionFactory.php` | Test data only. |
| `tests/Feature/Admission/{AdmissionManagementTest,AdmissionWorkflowTest,AdmissionSecurityAndFilterTest}.php` | See §8. |
| `docs/api/admission-management.md` | Client-facing reference, matching Module 04's doc shape. |
| `docs/audits/module-05-admission-management-audit.md` | This file. |

### 2.2 Modified

| File | Change | Why |
|---|---|---|
| `routes/api.php` | Added the `admissions` route group. | New endpoints. |
| `database/seeders/DatabaseSeeder.php` | Added `AdmissionPermissionSeeder::class` after `StudentPermissionSeeder::class`. | Ordering is load-bearing, exactly as documented for the three seeders before it. |
| `tests/TestCase.php` | Added `AdmissionPermissionSeeder::class` to the seeded baseline. | So every feature test starts from a known permission baseline that now includes Module 05. |
| `tests/Pest.php` | Added Admission helpers (`admissionCreatePayload`, `pendingAdmission`, `decidedAdmission`, `selectableClassLevel`, etc.), following the existing per-module helper-block convention. | Test ergonomics only; no production code touched. |

### 2.3 Kept unchanged

`Student` model/migration/service/controller/requests/resource, all of Module 02's academic
models and services, `User`, `Role`, `Permission`, the Gate wiring in
`AuthServiceProvider`/`bootstrap/app.php`, `ApiResponse`, `ListRequest`,
`BusinessRuleViolation`. Nothing about how these work was touched; `AdmissionService` only
*calls* `StudentService::create()`.

### 2.4 Deliberately not created

| Rejected | Reason |
|---|---|
| `AdmissionRepository` / `AdmissionManager` / `AdmissionQueryBuilder` | One table, one service. Every prior module (02–04) found a service to be the right weight and nothing above it necessary; nothing about this module's complexity says otherwise. |
| `AdmissionDTO` / `AdmissionInterface` | Arrays in, Eloquent models out, exactly as every other service in this project. |
| `AdmissionTransformer` | `AdmissionResource` (a Laravel API Resource) already is this. |
| `AdmissionObserver` / `AdmissionEvent` / `AdmissionListener` | Nothing in this project observes model events (`DatabaseSeeder`'s own docblock is explicit that they are left on only for Module 02's `active_marker` hooks). The terminal rule is a service rule, belongs where it cannot be bypassed, and needs no event. |
| `AdmissionPermissionService` / `AdmissionFactoryService` | The Gate already resolves permissions from the database; a second service to ask it would be a wrapper with no behaviour. |
| `AdmissionPolicy` | Every route in this project is permission-gated middleware, not a policy. Introducing one here would duplicate that without adding anything a policy is for (no per-record ownership rule exists - see §7.1). |
| A separate `Applicant`/`Application` model | See §3. |
| Parent/guardian tables | Never mentioned in scope. Speculative. |
| Document upload / payment functionality | Explicitly out of scope for this module. |
| `admissions.status` permission | No endpoint reaches status as a generic field - see §5. |
| Enrollment, class/section assignment | A future module's job. `entry_class_level_id` is the one exception, and it is documented in §4 as non-authoritative. |

---

## 3. Why there is no separate Application/Applicant domain

The brief asks this question directly: does the system need an independent application stage
before admission?

**No**, and the reasoning is structural rather than a shortcut:

- Nothing in the existing codebase (routes, roles, seeders, or the brief itself) names an actor
  distinct from the decision-maker who would "own" an intake stage. `RoleSeeder` gives
  `REGISTRAR` the whole of "handling admissions" as one responsibility.
- The `Admission` row *already* models "applied, not yet decided" via `status = PENDING`. Adding
  a fourth table (`Applicant`) sitting in front of it would duplicate exactly the fields this
  table already holds (name, DOB, gender, target session) for no behaviour gained.
- There is no public-facing, self-service intake anywhere in this project - every module so far
  is staff/registrar-operated CRUD behind a bearer token. An `Applicant` model would earn its
  keep if unauthenticated members of the public could start an application and finish it later
  (a multi-step, resumable flow with its own identity separate from a decision record); nothing
  in this project's architecture or brief asks for that.

If a public application portal is ever built, it is additive: a new, unauthenticated intake
endpoint that creates the *same* `Admission` row this module already defines, with `PENDING` as
its starting state. Nothing here would need to change.

---

## 4. The lifecycle decision

Adopted: `PENDING → ADMITTED | REJECTED | WITHDRAWN`.

**Not adopted:** the brief's suggested `APPLIED → UNDER_REVIEW → ACCEPTED → ADMITTED` pipeline.

Reasoning, by the same method Module 04 used to reject a `PENDING` student status: a pipeline
stage is only real if something in the domain depends on it - a role assigned to it, a rule that
checks for it, a UI that gates on it. `UNDER_REVIEW` would need a reviewer distinct from the
decider, and nothing in this project draws that line: `RoleSeeder` names `REGISTRAR` and `ADMIN`
as jointly handling admissions with no sub-split. Collapsing `APPLIED` and `UNDER_REVIEW` into
one `PENDING` state removes a column that would exist only to be looked at, never branched on.

`ACCEPTED` (a decision made, pending the mechanical act of enrolling) versus `ADMITTED` (the
mechanical act done) was also considered and rejected for the same reason: nothing in this
project separates "we decided yes" from "the student record now exists" into two humanly
meaningful moments requiring two permissions. `AdmissionService::admit()` does both atomically,
inside one transaction, so there is never a real interval during which "accepted but not yet
admitted" is an observable, actionable state.

Three terminal states rather than one, mirroring Module 04's two-terminal-state student status
for the identical reason: `ADMITTED`, `REJECTED` and `WITHDRAWN` are different facts about how
an application ended, and collapsing them would lose "how many of this year's applicants did we
actually want, versus how many changed their mind" - a real reporting question.

**Transition rule (`AdmissionService::assertPending()`):** only `PENDING` may move, to any of
the three terminal states; no terminal state may move anywhere, including back to `PENDING` or
sideways to another terminal state. Unlike Module 03's `staff.activate`/`staff.deactivate` -
where re-issuing the same transition is an idempotent success - repeating an admission decision
is refused outright, because the decision has a side effect (`ADMITTED` creates a `Student`) and
is not a reversible toggle like employment status. This single guard is what makes "admitted
twice" and "rejected after already admitted" both structurally impossible rather than merely
discouraged.

---

## 5. Identifier ownership

| Table | Identifier | Ownership |
|---|---|---|
| `admissions` | `admission_number` | This attempt. Derived like `student_number`/`staff_number` (primary-key-derived, UUID-reservation transaction, never `count() + 1`). |
| `students` | `student_number` | The person, unchanged from Module 04. Never written by this module except by *calling* `StudentService::create()`, which derives its own. |
| `enrollments` (future) | not yet designed | Out of scope. |

No competing identifiers: the two tables' number columns are independent, on independent
tables, and neither is copied onto the other. `AdmissionService::admit()` does not set
`students.student_number` directly - `StudentService::create()` does, exactly as it does for
every other caller.

---

## 6. Admission and Student creation

**Decision: only `admit()` creates a `Student`, and it always creates a new one.**

- `POST /admissions` creates no `Student`. The applicant is not yet a pupil.
- `POST /admissions/{id}/admit` is the *only* path to a `Student`, wrapped in one
  `DB::transaction()` alongside the admission's own `status`/`student_id`/`decided_at` write.
  If `StudentService::create()` throws, the whole transaction rolls back and the admission stays
  `PENDING` with no student created - verified by
  `AdmissionWorkflowTest::"leaves no student and no status change when admit fails partway
  through"`.
- Duplicate student creation is prevented **structurally**, not by an extra existence check:
  `admit()` is gated by `assertPending()`, so the second call against an already-`ADMITTED`
  admission never reaches `StudentService::create()` at all. There is no "is `student_id`
  already set?" branch to forget, because the state machine makes that branch unreachable.
- `admissions.student_id` is nullable, unique, and **has no path any request can reach**. It is
  written in exactly one place: `AdmissionService::admit()`, via `forceFill()`. No
  Store/Update/Decide request accepts a `student_id` key. This is the same protection Module 04
  gives `students.user_id` - the absence of the key, not a rejected value, is what prevents a
  holder of `admissions.update` from linking an admission to an arbitrary existing pupil.
- No login account is created either. `StudentService::create()` never creates one (Module 04's
  own boundary), so an admitted applicant becomes a pupil with `account_status: null`, exactly
  like every other `POST /students` call.

### 6.1 An accepted limitation, documented rather than worked around

**Re-admitting a previously `WITHDRAWN` or `GRADUATED` student is not supported.** Every
`ADMITTED` admission creates a **brand-new** `Student` row; there is no path to link an
admission to an *existing* person's record, because:

1. Accepting a client-supplied `student_id` on create or on admit would reopen the exact IDOR
   surface §6 just closed (linking to an arbitrary student by guessing an id).
2. Even if a legitimate "this returning pupil already has a record" lookup existed,
   `StudentService::update()` already refuses **every** amend to a terminal (`GRADUATED`/
   `WITHDRAWN`) student, by design (Module 04 audit §3.5: "a departed pupil's record is closed").
   Reactivating one is not a capability Module 04 exposes, and this module does not add a
   back door to it.

The consequence: a person who withdrew and later re-applies gets a second, independent
`Student` row if re-admitted. Merging a returning pupil's history is future work, requiring a
deliberate decision in whichever module (likely Enrollment, or a dedicated identity-merge tool)
chooses to expose it - the same posture Module 04's own audit took toward its SQL Server
nullable-unique-index question: named, not silently patched.

---

## 7. Academic session and entry level

- `academic_session_id` is **required**, using Module 02's own `AcademicSession` model - no
  second session table or status system. Validated with an ordinary `Rule::exists(...)->whereNot
  ('status', 'COMPLETED')`, the same technique already used for scoped uniqueness elsewhere in
  this project; no new validation mechanism introduced.
- `entry_class_level_id` is **optional**, using Module 02's `ClassLevel` model, restricted to
  `CatalogStatus::ACTIVE` (the existing `scopeSelectable()` definition). It is a fact about what
  the applicant is seeking, and `AdmissionResource` never presents it as a placement.
- Neither field is copied onto the `Student` `admit()` creates. `StudentService::create()`'s
  signature has no such parameters, so there is no code path even attempting to.
- A future `Enrollment` module remains the sole authority for `class_id`/`section_id`.

### 7.1 A known, accepted gap: deleting a referenced session or class level

`admissions.academic_session_id` and `admissions.entry_class_level_id` both use
`restrictOnDelete()`, so the database itself refuses to orphan an admission - no data
corruption is possible. However, `AcademicSessionController::destroy()` and
`AcademicStructureService::deleteClassLevel()` (Module 02, unmodified) only pre-check their
*own* known dependents (terms; classes) and do not know about `admissions`. Attempting to
delete a session or class level that still has admissions against it will therefore surface as
a raw database-constraint failure rendered by the generic 500 handler, rather than the friendly
422 Module 02 gives for its own dependents.

This is **not fixed here**, on the same principle Module 04's own audit applied to a comparable
gap (§6 of that audit): fixing it means editing Module 02's service, and "do not redesign
completed modules" / "do not refactor unrelated code" outweighs a cosmetic error-status
improvement when the DB-level guarantee (no corruption) already holds. Whoever next touches
`AcademicStructureService`/`AcademicSessionService` should add an `admissions()->exists()` guard
alongside the existing ones.

---

## 8. Permissions

`admissions.view`, `admissions.create`, `admissions.update`, `admissions.admit`,
`admissions.reject`, `admissions.withdraw` - six, one per real operation, seeded by
`AdmissionPermissionSeeder` with `syncWithoutDetaching()` after `StudentPermissionSeeder` (order
is load-bearing, identically to every prior module).

`SUPER_ADMIN`, `ADMIN` and `REGISTRAR` hold all six. `STAFF` and `STUDENT` hold none.

This is a deliberate **departure** from Module 03's precedent of withholding a state-transition
permission from `REGISTRAR` (there, `staff.activate`/`staff.deactivate`). The difference is not
arbitrary: Module 03 withheld those two because ending someone's *employment* is a supervisory
decision outside a registrar's ordinary duties. Admissions are the opposite case -
`RoleSeeder`'s own description of `REGISTRAR` is *"Handles admissions, enrollment and student
records"* - so withholding `admissions.admit`/`reject`/`withdraw` from the role whose stated job
is admissions would leave them unable to do it. Per the brief's own instruction (§16 of the
non-negotiable rules): follow the actual codebase's stated intent over an assumed default.

No `admissions.delete` and no `admissions.status`: no endpoint exists for either, so a
permission for them would be a grant with no meaning.

---

## 9. Deletion policy

**No delete endpoint, no permission.** An admission is a historical business record, following
Module 03 and Module 04's identical reasoning (staff are not deleted; pupils are not deleted).
`WITHDRAWN` is the record-preserving alternative to deletion for an applicant who backs out, the
same relationship `WITHDRAWN` has to deletion on the student roll.

---

## 10. Security review performed

- **Mass assignment**: `Admission::$fillable` excludes `status` and `student_id` entirely; no
  Store/Update/Decide request has a key for either, so there is no value to strip - the same
  "absence of the key, not a rejected value" pattern Module 04 uses for `students.user_id`.
  Verified by tests injecting `status`, `student_id`, `role`, `role_id`, `email`, `password`
  into every write payload and asserting none of them land.
- **IDOR / IDOR-adjacent duplicate linkage**: closed structurally (§6) rather than validated
  against - there is no request field that could name an arbitrary student to link.
- **Unauthorized status transitions**: `assertPending()` is the single gate all three
  transitions share; verified for every pairwise combination the brief names (admit-twice,
  reject-after-admit, admit-after-reject, withdraw-after-reject, reject-after-withdraw).
- **Unauthorized access by role**: full authorization matrix tested (guest, `STAFF`,
  `STUDENT`, suspended account, per-permission detachment for `create`, `update`, `admit`).
- **Race / duplicate creation**: `admission_number` uses the UUID-reservation-inside-transaction
  technique already proven for `student_number`/`staff_number`; duplicate `Student` creation
  from one admission is prevented by the state machine, not a second `exists()` check that could
  itself race.
- **Transaction integrity**: a forced failure inside `admit()`'s transaction (after
  `StudentService::create()` has already inserted a row) is asserted to leave **zero** students
  and the admission still `PENDING` - the exact "student creation succeeds but admission update
  fails" edge case named in the brief.
- **Sensitive data exposure**: `AdmissionResource`'s field list is exact; the nested `student`
  goes through `StudentResource`, so no email or account internals leak through the admission
  endpoint that Module 04 already decided not to expose through the student endpoint.
- **Validation bypass**: every referenced record (`academic_session_id`, `entry_class_level_id`)
  is checked with a database-backed `exists` rule with a `where` clause restricting to a valid
  state, not merely "is this an integer".

---

## 11. What this module owes the next one

- `entry_class_level_id` is a stated preference, never a placement. The first enrollment module
  needs its own `class_id`/`section_id`/`academic_session_id` on an `enrollments` table, not a
  repurposing of this column.
- `admissions.student_id` is nullable, unique, and writable **only** by `AdmissionService`. Do
  not widen who can write it "for convenience" - the absence of a path is the security boundary.
- Re-admitting a returning pupil (§6.1) is unimplemented on purpose. Whoever builds it must
  decide, deliberately, how it interacts with `StudentService::update()`'s terminal-freeze rule
  - it cannot be added as a small edit to `AdmissionService::admit()` alone.
- The Module 02 delete-guard gap (§7.1) is real but non-corrupting. Worth a one-line fix
  whenever `AcademicStructureService`/`AcademicSessionService` is next touched for another
  reason.

---

## 12. Verification

See the final report delivered alongside this audit for the test run, `Pint`, and
migration/seed results.
