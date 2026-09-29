# Module 07 — Subjects & Subject Catalog Management: Architecture Audit

Status: **implemented**
Scope: the reusable subject catalogue, and the offering of its subjects to classes.

---

## 1. What was already in the codebase

Verified by repository-wide search before any code was written:

| Thing | State found | Consequence |
|---|---|---|
| `subjects`/`class_subjects`/`teacher_subject` table, model, service, controller, requests, resource, tests | none | Greenfield. |
| `subject` anywhere | only forward-looking comments (`classes` migration: *"no student, subject or teacher column here... those are later modules"*; `routes/api.php`: *"when subjects, attendance and results arrive..."*) | Confirms rather than contradicts the module boundary. |
| `AcademicStructureService` | one service for `ClassLevel`/`SchoolClass`/`Section` together, with its own docblock explaining why: *"a single nested hierarchy with shared rules rather than three unrelated catalogues"* | Directly mirrored for `SubjectService` handling `Subject`+`ClassSubject`. |
| `ValidatesCatalogRecord` (Module 02) | a shared trait for name/code/sort_order/status, globally-unique-or-parent-scoped, uppercase-folded code | **Reused verbatim** for `Subject` - its shape is structurally identical to `ClassLevel`'s. |
| `CatalogStatus` (`ACTIVE`/`INACTIVE`/`ARCHIVED`) | exists, explicitly documented as shared across the academic structure | Reused directly for both `Subject` and `ClassSubject` - no new status enum introduced. |
| `guardSelectableParent()` (Module 02) | checks a parent's selectability **both** in the Form Request (HTTP path) and again in the service (any caller) - a deliberate, documented double-check | Mirrored for `ClassSubject`'s own class/class-level checks. |
| `StaffType::TEACHING`/`NON_TEACHING` | exists, is explicitly not an authentication role | Confirmed as the only teacher-adjacent concept; no `Teacher` entity created, none needed. |
| `restrictOnDelete` convention | established | Followed for both of `class_subjects`' foreign keys. |
| `Rule::unique(...)->where(...)` scoped-uniqueness technique | established (`ValidatesTerm`, `ValidatesEnrollmentRecord`) | Reused verbatim for "a class should not have the same subject attached twice". |
| `Model::unguarded()` inside factory creation | confirmed via `AdmissionFactory`/`EnrollmentFactory`'s own reliance on it | Let `ClassSubjectFactory` set `school_class_id`/`subject_id` directly despite neither being mass-assignable through the API. |

Nothing contradicted the brief's assumed shape. The one place a deliberate choice departs from
the brief's own suggested naming is the permission scheme (§6) - documented below, per
non-negotiable rule 19.

---

## 2. Files

### 2.1 Created

| File | Why |
|---|---|
| `database/migrations/2026_10_01_090000_create_subjects_table.php` | The catalogue table. |
| `database/migrations/2026_10_01_090001_create_class_subjects_table.php` | The offering table. |
| `app/Models/Subject.php` | Structural twin of `ClassLevel`. |
| `app/Models/ClassSubject.php` | The offering; `school_class_id`/`subject_id` excluded from `$fillable` so no amend can ever repoint it. |
| `app/Services/Subject/SubjectService.php` | One service for both entities, mirroring `AcademicStructureService`. |
| `app/Http/Requests/Subject/{Store,Update,List}SubjectRequest.php` | Subject's Form Requests - all three delegate to `ValidatesCatalogRecord`. |
| `app/Http/Requests/Subject/{Store,Update,List}ClassSubjectRequest.php` | Class subject's Form Requests. |
| `app/Http/Requests/Subject/Concerns/ValidatesClassSubjectRecord.php` | The one genuinely new validation trait this module needed. |
| `app/Http/Resources/{Subject,ClassSubject}Resource.php` | Explicit field lists; the latter nests `SchoolClassResource`/`SubjectResource`. |
| `app/Http/Controllers/Api/V1/Subject/{Subject,ClassSubject}Controller.php` | Five actions on `Subject` (with `destroy()`), four on `ClassSubject` (without). |
| `database/seeders/SubjectPermissionSeeder.php` | Seven permissions, `syncWithoutDetaching()`. |
| `database/factories/{Subject,ClassSubject}Factory.php` | Test data only. |
| `tests/Feature/Subject/{SubjectManagementTest,SubjectSecurityAndFilterTest,ClassSubjectManagementTest,ClassSubjectSecurityAndFilterTest,SubjectIntegrityTest}.php` | See §9. |
| `docs/api/subject-management.md` | Client-facing reference. |
| `docs/audits/module-07-subject-management-audit.md` | This file. |

### 2.2 Modified

| File | Change | Why |
|---|---|---|
| `routes/api.php` | Added the `subjects` and `class-subjects` route groups. | New endpoints. |
| `database/seeders/DatabaseSeeder.php` | Added `SubjectPermissionSeeder::class` after `EnrollmentPermissionSeeder::class`. | Ordering is load-bearing, exactly as documented for every seeder before it. |
| `tests/TestCase.php` | Added `SubjectPermissionSeeder::class` to the seeded baseline. | Every feature test now starts from a baseline that includes Module 07. |
| `tests/Pest.php` | Added Subject/ClassSubject helpers, following the existing per-module helper-block convention. | Test ergonomics only; no production code touched. |

### 2.3 Kept unchanged

Everything in Modules 01–06, **including `SchoolClass` and `ClassLevel` themselves** - no
reverse relation was added to either. `SubjectService` queries `ClassSubject` and `SchoolClass`
directly wherever it needs to; it does not require (and this module does not add) a
`classSubjects()` relation on `SchoolClass`, matching the discipline every prior module already
established: only the *owning* side of a new relationship gets a method, never the model being
referenced.

### 2.4 Deliberately not created

| Rejected | Reason |
|---|---|
| `SubjectRepository` / `SubjectManager` / `SubjectQueryBuilder` | One shared service, the same weight `AcademicStructureService` already settled on for three related catalog entities. |
| `SubjectDTO` / `SubjectInterface` | Arrays in, Eloquent models out, matching every other service in the project. |
| `SubjectTransformer` | `SubjectResource`/`ClassSubjectResource` already are this. |
| `SubjectObserver` / `SubjectEvents` / `SubjectListeners` | Nothing in this project observes model events. The class-level-active check and the unique-index race guard are service rules, and belong where they cannot be bypassed. |
| `SubjectFactoryService` / `SubjectPermissionService` | The Gate already resolves permissions from the database; a wrapper adds no behaviour. |
| A `SubjectPolicy` | Every route in this project is permission-gated middleware; there is no per-owner scoping rule for either resource to express. |
| A new `Teacher` model/role | `StaffType::TEACHING`/`NON_TEACHING` already exists and is explicitly reused; a future assignment module builds on `Staff`, not a new entity. |
| `teacher_id`/`staff_id`/`assessment_id`/`score` on either table | Explicitly out of scope - see §3. |
| A `ClassSubject` delete endpoint | It is the anchor future academic records will reference - see §5. |
| A dedicated `ValidatesCatalogRecord`-style trait for Subject | Reused the existing one verbatim instead - see §4. |
| A `search` filter on class subjects | No text field exists on that table to search. |
| Teacher Assignment, Assessments, Scores, Grading, Results, Promotion, Attendance, Timetable | Explicitly future modules; not touched. |

---

## 3. Domain model

```
Subject         = the reusable catalogue definition (Mathematics, Biology). Global, unique
                  name and code, ordered, retired with CatalogStatus. No class, teacher or
                  assessment column.
Class Subject   = one class's offering of a subject (JSS 2 -> Mathematics). References a
                  school_class_id and a subject_id, and nothing else about a teacher, an
                  assessment or a score.
Subject Teacher = a FUTURE module. Will reference class_subjects.id, not subjects.id - a
                  teacher is assigned to "Mathematics as taught in JSS 2", never to
                  "Mathematics" in the abstract. Not implemented here.
Assessment      = a FUTURE module. Not implemented here.
```

Neither `Subject` nor `ClassSubject` has, or needs, a foreign key toward `Staff`,
`Enrollment`, or any future `Assessment`/`Score`/`Result` table. The chain the brief describes
(`Enrollment → Class Subject → Assessment → Score → Result`) is satisfied by `ClassSubject`
existing as its own addressable row with a stable primary key - nothing more is required of
this module to make that chain possible later.

---

## 4. Why Subject reuses `ValidatesCatalogRecord` directly

`Subject`'s validated shape - a required, globally-unique `name` (max 100) and `code` (max 20,
upper-cased), an optional `sort_order`, an optional `CatalogStatus` - is not merely *similar* to
`ClassLevel`'s, it is **identical**, because both are top-level catalogue entries with no parent
to scope uniqueness to. Writing a second trait with the same rules, the same messages and the
same normalisation would create two implementations of one rule that could silently drift apart
- exactly what `ValidatesCatalogRecord`'s own docblock says it exists to prevent for
`ClassLevel`/`SchoolClass`/`Section`. `StoreSubjectRequest`/`UpdateSubjectRequest` therefore
`use App\Http\Requests\Academic\Concerns\ValidatesCatalogRecord;` directly, across the module
boundary, and add nothing of their own beyond `catalogTable(): string { return 'subjects'; }`.

`ClassSubject` does **not** fit that trait - it has no `name`/`code` at all, only two foreign
keys and a status - so it gets its own small `ValidatesClassSubjectRecord`, matching the shape
`ValidatesEnrollmentRecord` already established: a Store-only `classSubjectReferenceRules()`
and a Store+Update `classSubjectStatusRule()`/message set.

---

## 5. Why `ClassSubject` has no delete endpoint, but `Subject` does

**`Subject` supports `DELETE`, guarded**, because it is structurally in the same family as
`ClassLevel`/`SchoolClass`/`Section` - all three already support a guarded delete in this
project - and the guard (refuse while any `class_subjects` row references it, regardless of
that row's own status) mirrors `deleteClassLevel()`'s "still has classes" check exactly.

**`ClassSubject` has no delete endpoint at all**, by analogy to `Enrollment` rather than to
`Section`: it is the closest domain equivalent to an enrollment - the offering-level row a
future chain of academic records (`Assessment`, `Score`, `Result`, and a `SubjectTeacher`) will
reference - not a leaf catalogue item like a section. The brief's own instruction is explicit
that once academic records reference a class subject, destructive deletion becomes
inappropriate; rather than wait for that future module to discover the problem, this module
commits to the safer answer now. `status: INACTIVE` through the ordinary `PUT` is "removing a
subject from a class" without ever destroying the row.

This is a **within-module use of two different policies for two different entities**, each
justified by which existing precedent the entity actually resembles - not an inconsistency.

---

## 6. Permission naming: plural, departing from the brief's own example

The brief's own example uses singular names (`subject.view`, `class_subject.create`). This
project's dominant convention, established across Modules 02, 04, 05 and 06, is **plural**
(`academic_sessions.*`, `classes.*`, `students.*`, `admissions.*`, `enrollments.*`) - `staff.*`
is the project's own documented outlier, explicitly left alone rather than renamed. Per
non-negotiable rule 19, this module follows the codebase's actual dominant convention:
`subjects.*` and `class_subjects.*`.

---

## 7. Class/class-level validation

Mirrors Module 06's enrollment validation exactly, for the identical reason:

- `school_class_id` must exist and be `CatalogStatus::ACTIVE` - checked declaratively in the
  Form Request (`Rule::exists('classes', 'id')->where('status', 'ACTIVE')`).
- The class's own **class level** must independently be `ACTIVE` - `AcademicStructureService`
  permits a class to remain nominally `ACTIVE` after its class level has been archived (its own
  `updateClass()` comment: *"amending the name of a class that still sits in a level which has
  since been archived is legitimate housekeeping"*), so this cannot be assumed from the class's
  own status and is checked against a loaded `SchoolClass::with('classLevel')` in
  `SubjectService::assertClassSelectable()`.
- `subject_id` must exist and be `ACTIVE`.
- The parent-selectability check is deliberately repeated in the service, not left to the Form
  Request alone - the same double-check `AcademicStructureService::guardSelectableParent()`
  documents for its own, structurally identical check one level up the same hierarchy.

---

## 8. Database design

| Table | Column | Constraint |
|---|---|---|
| `subjects` | `name` | `unique`, max 100 |
| `subjects` | `code` | `unique`, max 20, upper-cased |
| `subjects` | `sort_order` | indexed, default 0 |
| `subjects` | `status` | default `ACTIVE` |
| `class_subjects` | `school_class_id` | `foreignId` → `classes`, `restrictOnDelete` |
| `class_subjects` | `subject_id` | `foreignId` → `subjects`, `restrictOnDelete` |
| `class_subjects` | `status` | indexed, default `ACTIVE` |

**Uniqueness**: `class_subjects` carries `unique(school_class_id, subject_id)` - the
database-level half of "a class should not have the same subject attached twice", verified
directly (not merely asserted) by calling `SubjectService::createClassSubject()` twice in a row
from a test, bypassing the Form Request's own check, and confirming the second call raises the
same friendly `BusinessRuleViolation` rather than a raw `QueryException`.

**Foreign-key deletion behaviour verified directly**: `SubjectIntegrityTest` attempts to delete
a class that a `class_subjects` row references and confirms no cascade ever occurs - the class
and the offering both survive the attempt.

### 8.1 A known, accepted gap (documented, not fixed) - now a third instance

`AcademicStructureService::deleteClass()` checks only `sections()->exists()`; it does not know
about `class_subjects` (or, per Module 06's own audit, `enrollments`). A class with **no**
sections but **an** offering will pass that check and then hit the database's
`restrictOnDelete` directly, surfacing as a generic `500` rather than a friendly `422` - the
database-level guarantee (no corruption, ever) holds regardless, which is what
`SubjectIntegrityTest` actually verifies.

This is **not fixed here**, on the identical principle Module 05's and Module 06's own audits
already applied to the same two Module 02 services: editing a completed module's service for a
cosmetic error-status improvement is a larger change than this module's scope justifies, given
the underlying safety property already holds without it. `AcademicStructureService::deleteClass()`
now has **three** undocumented dependents it does not check for (`sections` it does check;
`class_subjects` and `enrollments` it does not) - worth a single consolidated fix whenever that
service is next touched for another reason.

---

## 9. Permissions

`subjects.view/create/update/delete`, `class_subjects.view/create/update` - seven, seeded by
`SubjectPermissionSeeder` with `syncWithoutDetaching()` after `EnrollmentPermissionSeeder`
(order is load-bearing, identically to every prior module).

`SUPER_ADMIN` and `ADMIN` hold all seven. `REGISTRAR` holds everything except
`subjects.delete` - the identical split `AcademicPermissionSeeder` already applies to
`class_levels.delete`/`classes.delete`/`sections.delete`. `STAFF` and `STUDENT` hold none,
**regardless of `StaffType`** - verified directly with a `NON_TEACHING` staff member, per the
brief's explicit instruction not to assume every `STAFF` row is a teacher and not to grant
subject-management access merely for teaching.

---

## 10. Security review performed

- **Authorization**: full matrix tested (guest, `STAFF` of both `StaffType`s, `STUDENT`,
  suspended account, per-permission detachment for `create`, and the `REGISTRAR`-vs-`ADMIN`
  delete split).
- **Mass assignment**: `ClassSubject::$fillable` lists only `status`; `school_class_id` and
  `subject_id` have no key in *any* request, verified by injecting both plus `status` into
  every write payload and asserting none of them land. `Subject`'s create endpoint is verified
  against an injected `id` and `created_at`.
- **IDOR**: verified that a route parameter naming one class subject's id cannot be used to
  amend a *different* one.
- **Academic/data integrity**: a section-less class-level mismatch (a class whose own status is
  `ACTIVE` but whose class level is `ARCHIVED`), a retired class, a retired subject, and a
  duplicate `(class, subject)` pair are each refused and each asserted not to have created a
  row. A subject's status and its offerings' statuses are verified independent in both
  directions. A class subject is verified to survive its class being retired afterwards.
- **Concurrency**: the unique-index race is exercised directly (bypassing the Form Request's
  own check) and confirmed to surface as the same `BusinessRuleViolation` message, not a raw
  `500`.
- **Foreign-key deletion behaviour**: verified directly rather than assumed - see §8.1.

---

## 11. What this module owes the next one

- `class_subjects.id` is the stable anchor a future Teacher/Class/Subject Assignment module
  must reference - never `subjects.id` directly, and never a new pivot of its own.
- A future Assessment/Score/Result chain references `class_subjects.id` too, for the same
  reason: an assessment belongs to "Mathematics as taught in JSS 2", not to "Mathematics" in
  the abstract.
- The Module 02 delete-guard gap (§8.1) is real but non-corrupting - now spanning three
  undocumented dependents (`class_subjects`, `enrollments`, and whatever comes next) and worth
  a single consolidated fix whenever `AcademicStructureService` is next touched.
- Nothing in this module assumes a `STAFF` row is a teacher in any enforceable way beyond
  `StaffType::TEACHING` already existing; the next module is free to build its own eligibility
  rule on top of it without this module needing to change.

---

## 12. Verification

See the final report delivered alongside this audit for the test run, `Pint`, and
migration/seed results.
