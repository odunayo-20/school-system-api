# Result Compilation API

Turning raw assessment scores into a compiled subject result.

Module 12. Four endpoints, one table, and one deliberate restriction: **this module compiles
one student's one-subject result for one term - it never approves, publishes, prints a report
card, promotes a student, exposes anything to a student's own login, or computes a cross-subject
aggregate (an overall term average, a class position).**

- [0. Read this first: what a result is, and what this module does not do](#0-read-this-first-what-a-result-is-and-what-this-module-does-not-do)
- [1. Conventions](#1-conventions)
- [2. The result resource](#2-the-result-resource)
- [3. Endpoints](#3-endpoints)
- [4. The calculation](#4-the-calculation)
- [5. Permissions](#5-permissions)
- [6. Client checklist](#6-client-checklist)

---

## 0. Read this first: what a result is, and what this module does not do

```
Enrollment (Amina Yusuf, JSS 1, 2025/2026)
  + ClassSubject (JSS 1 Mathematics)
  + Term (First Term)
  → Result   percentage 87.50   grade A   grade_point 5.00   remark "Excellent"   status COMPILED
```

A **Result** is the compiled outcome of every `ACTIVE` [Assessment](assessment-configuration.md)
configured for one class subject and term, as scored (Module 10) for one student's
[Enrollment](enrollment-management.md) - never a raw student id, for the identical reason
Module 10's `Score` references an enrollment rather than a student: a result belongs to the
placement the scores were recorded against, never merely to the person.

**`enrollment_id`, `class_subject_id` and `term_id` are all three independently necessary.** A
class subject is a standing curriculum fact with no session or term of its own (Module 07); an
enrollment is scoped to a whole academic session, not to any one of its terms (Module 06). A
result is the one row in this project that needs all three coordinates at once.

**ONE table, not a parent `Result` plus child `ResultItem` rows.** This project's own domain
stops at "one student's one subject result for one term" - there is no cross-subject aggregate
in scope for this module, so there is no second, genuinely distinct concept for a parent
envelope to hold.

**This table holds no raw total, no per-assessment breakdown, and no snapshot of the scores
that produced it.** `Score` remains the sole authoritative raw input. A client wanting the rows
behind a result calls `GET /scores` with the same `enrollment_id`, `subject_id` and `term_id`
filters Module 10 already exposes, rather than this module duplicating that data.

**This module does not implement Result Approval, Result Publication, Report Cards, Promotion,
a Result Checker for students, Attendance, Timetable, or Notifications.** A compiled result is
working data for teachers and administrators only; every one of those is a later module. The
only hook this module leaves for them is `status: LOCKED`, checked before every recompile and
otherwise unused today - see §4.4.

---

## 1. Conventions

Inherited from the [API reference](README.md): bearer tokens, `Accept: application/json`, the
`{ "data": ..., "message": ... }` envelope, `meta`/`links` as siblings of `data`, and
200/201/401/403/404/405/422/429.

### 1.1 There is no create, update or delete endpoint

A result is written only through **compile** (§3.2) or **bulk compile** (§3.3) - a calculation,
never a raw create or amend of client-supplied values. `POST`, `PUT`, `PATCH` and `DELETE`
against `/results/{id}` are all **405**. Recompiling (the same operation, run again after a
score correction) is how an existing result changes - see §4.3.

### 1.2 Compile answers `200`, not `201`

This is a calculation that may create or update the underlying row depending on whether one
already exists. The client is always asking for the same thing either way - the current
compiled result - so the status code does not depend on which happened underneath.

---

## 2. The result resource

```json
{
  "data": {
    "id": 41,
    "enrollment": { "id": 12, "student": { "...": "..." }, "academic_session": { "...": "..." }, "school_class": { "...": "..." }, "section": { "...": "..." } },
    "class_subject": { "id": 7, "school_class": { "...": "..." }, "subject": { "...": "..." } },
    "term": { "id": 4, "name": "First Term", "academic_session": { "...": "..." } },
    "percentage": "87.50",
    "grade": "A",
    "grade_point": "5.00",
    "remark": "Excellent",
    "status": "COMPILED",
    "created_at": "2026-10-06T09:00:00+00:00",
    "updated_at": "2026-10-06T09:00:00+00:00"
  },
  "message": "..."
}
```

Every related record is rendered through its own module's resource -
[`EnrollmentResource`](enrollment-management.md), [`ClassSubjectResource`](subject-management.md),
`TermResource` - so this module maintains no second, divergent field list for any of them.

`percentage` and `grade_point` are decimal strings, not floats - matching
`assessments.max_score`'s own precedent, so a value like `87.50` is never subject to binary
floating-point rounding.

`grade`, `grade_point` and `remark` are simply whatever Module 11's grading scale resolved,
copied through unchanged - this module implements no grading letters, GPA compilation or
report-card logic of its own. All three are `null` together whenever `status` is
`INCOMPLETE`, or no grading scale is configured for the enrollment's class level, or the
percentage falls in a gap no band covers.

---

## 3. Endpoints

| Method | Path | Permission |
|---|---|---|
| `GET` | `/results` | `results.view` |
| `GET` | `/results/{id}` | `results.view` |
| `POST` | `/results/compile` | `results.compile` |
| `POST` | `/results/bulk` | `results.compile` |

All four require an active bearer token (`auth:api` + `active`).

### 3.1 `GET /results`

| Parameter | Values | Notes |
|---|---|---|
| `enrollment_id` | integer | Must exist |
| `class_subject_id` | integer | Must exist |
| `term_id` | integer | Must exist |
| `student_id` | integer | Filters through the enrollment |
| `school_class_id` | integer | Filters through the enrollment |
| `section_id` | integer | Filters through the enrollment |
| `subject_id` | integer | Filters through the class subject |
| `academic_session_id` | integer | Filters through the enrollment |
| `status` | `INCOMPLETE`, `COMPILED`, `LOCKED` | |
| `per_page` | 1-100, default 15 | |

There is no `search` - a result holds no text field of its own; `?search=` is **422**, not
silently ignored. See §5 for how a `STAFF` caller's list is additionally scoped before any of
these filters is applied.

### 3.2 `POST /results/compile`

Compile - or recompile - one enrollment's result for one class subject and term.

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"enrollment_id":12,"class_subject_id":7,"term_id":4}' \
  http://localhost:8000/api/v1/results/compile
```

**200.** The **only** input this request accepts is the identifying triple - never a total, a
percentage, a grade or a grade point. Every calculated value is derived server-side from
`Assessment`, `Score` and the applicable `GradingScale`; a client sending `percentage`, `grade`,
`grade_point` or `status` in the body has them silently ignored, matching `StoreScoreRequest`'s
identical discipline for `max_score`.

**Every reference is validated, never trusted blindly:**

- `enrollment_id` must exist. It does **not** need to be `ACTIVE` - see §4.5.
- `class_subject_id` must exist and be `ACTIVE`, and its whole hierarchy (class, class level)
  must be `ACTIVE` too - re-checked on **every** compile, not only the first, because
  recompiling from scratch against the current academic context is the whole point of the
  operation.
- `term_id` must exist. It carries no "not completed" restriction - compiling after a term
  closes is the normal workflow, not an exception to guard against.
- The enrollment's `school_class_id` must match the class subject's, and its
  `academic_session_id` must match the term's - the identical context-match discipline
  `ScoreService::assertContextMatches()` already established for assessment/enrollment pairs.
- At least one `ACTIVE` assessment must be configured for the class subject and term, or the
  request is refused with **422** - there is nothing to compile.

### 3.3 `POST /results/bulk`

Compile - or recompile - every currently `ACTIVE` enrollment in a class subject's own class,
for one term: the natural "I've finished entering scores for my whole class" workflow.

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"class_subject_id":7,"term_id":4}' \
  http://localhost:8000/api/v1/results/bulk
```

**200.**

```json
{
  "data": [
    { "enrollment_id": 12, "result": { "...": "the same result resource as §2" }, "error": null },
    { "enrollment_id": 13, "result": { "...": "..." }, "error": null }
  ],
  "message": "Results compiled."
}
```

**Deliberately NOT all-or-nothing**, unlike Module 10's bulk score entry. Each student's
compile is an independent, idempotent calculation over already-recorded scores, not a new fact
being asserted that could be a client's mistake. A student with no scores yet compiles to
`status: "INCOMPLETE"` with `error: null` - that is a legitimate outcome, not a failure - and
never stops the rest of the class from compiling. `class_subject_id` and `term_id` are
validated, and the acting caller's authorization checked, **once**, against the shared context;
only currently `ACTIVE` enrollments in the class subject's own class and the term's own academic
session are swept - a withdrawn student's result is still reachable by naming their enrollment
directly through `POST /results/compile`.

### 3.4 `GET /results/{id}`

**200**, or **404** if the id does not exist, or **403** if a `STAFF` caller is not assigned to
teach the result's class subject for its academic session (§5.1).

---

## 4. The calculation

There is exactly **one** calculation path, on the server, never duplicated in a controller, a
resource, or anywhere else.

### 4.1 Weighting - consuming Module 09's own configuration, unchanged

- **If at least one scored assessment carries a configured `weight`,** the percentage is the
  sum of each scored *and* weighted assessment's own contribution
  (`score / max_score * weight`). A scored-but-unweighted assessment is simply excluded from
  the sum - `weight: null` already means "not part of the school's configured weighting scheme"
  under Module 09's own design, so it contributes nothing here either.
- **If no scored assessment carries a weight at all**, the percentage falls back to a plain
  ratio: total marks scored over total **possible** marks, where the denominator is every
  `ACTIVE` assessment's `max_score` - scored or not.

**In both branches, a missing score is never treated as zero, and the result is never
renormalized to look more complete than it is.** A missing assessment's weight (or its
`max_score`, in the unweighted branch's denominator) is simply never recovered by the
assessments that were scored - an incomplete result's percentage is always honestly capped
below what a fully-scored one could reach, never inflated by averaging over a smaller pool.

Rounding happens exactly once, on the final percentage.

### 4.2 Completeness, grade and the missing-scores policy

A result is `COMPILED` only when **every** `ACTIVE` assessment for the class subject and term
has a score on file for this enrollment; otherwise it is `INCOMPLETE`. `grade`, `grade_point`
and `remark` are resolved **only** when `COMPILED` - grading a partial picture would misrepresent
it as final. A missing grading scale, or a percentage no configured band covers, both resolve
to `null` rather than an error, matching `GradingService::calculate()`'s own "no match is not a
failure" posture from Module 11.

### 4.3 Idempotent recompilation

Compiling the same `(enrollment_id, class_subject_id, term_id)` triple again - after correcting
a score, adding a missing one, or simply re-running the same request - **updates the same row**.
The database's own `unique(enrollment_id, class_subject_id, term_id)` index is the race-safe
backstop for two concurrent first-time compiles of the same triple; row-level locking inside a
transaction serializes two concurrent recompiles of an already-existing result. A duplicate row
for the same triple is never produced by any path.

### 4.4 `status: "LOCKED"`

Not produced by this module - there is no endpoint that sets it. It exists purely as the
minimum hook a future Result Approval/Publication module needs: `POST /results/compile` and
`POST /results/bulk` both refuse to recompile a result already `LOCKED`, with **422**.

### 4.5 Enrollment need not be `ACTIVE`

Deliberately different from `Score`'s own create-time requirement. Compiling a result is
aggregating already-recorded history, not asserting a new fact about an active placement, so a
withdrawn student's result remains compilable.

---

## 5. Permissions

| Permission | Grants |
|---|---|
| `results.view` | `GET /results`, `GET /results/{id}` |
| `results.compile` | `POST /results/compile`, `POST /results/bulk` |

**No `results.create`/`.update`/`.delete`** - a result is written only through `compile()`, a
calculation, never a raw create, amend or delete of client-supplied values. **No separate
`.bulk` permission** - compiling a whole class subject's results in one call is the same
capability as compiling one student's, performed at the shape a class teacher actually works
in.

### 5.1 Role grants

| Role | `view` | `compile` |
|---|:--:|:--:|
| `SUPER_ADMIN` | ✓ | ✓ |
| `ADMIN` | ✓ | ✓ |
| `REGISTRAR` | ✓ | ✓ |
| `STAFF` | ✓ (scoped) | ✓ (scoped) |
| `STUDENT` | — | — |

**`REGISTRAR` holds full access, not view-only** - a deliberate departure from Module 08's
`teacher_assignments.*` and Module 11's `grading_scales.*`, both of which narrow `REGISTRAR` to
view-only as staffing/policy decisions outside `RoleSeeder`'s own stated duties. Compiling a
result is mechanical execution of an already-configured process (assessments, weights and
grading scales, all configured by others in earlier modules) over student records - the same
"admissions, enrollment and student records" territory `RoleSeeder` already names for
`REGISTRAR`.

**`STAFF` holds both, but every read and write is additionally scoped**: a teaching staff
member may only view or compile results for a class subject they hold an `ACTIVE`
`TeacherAssignment` for, **for the same academic session the term belongs to** - the identical
`(class_subject_id, academic_session_id)` pair `ScoreService` already established in Module 10,
and for the identical reason: `TeacherAssignment` is itself session-scoped, so the class subject
id alone is not enough. A Mathematics teacher cannot compile English results; a teacher assigned
to one class/session cannot compile another, even one they taught the previous session. The
scope is applied **unconditionally, before any filter**, so `?class_subject_id=` naming a class
subject outside a teacher's own assignments returns an empty list, never someone else's data.

**A student holds nothing over results in this module.** Raw compiled results, ahead of any
approval or publication, are working data for teachers and administrators; student-facing
access belongs to a future Result Checker module.

---

## 6. Client checklist

- [ ] Send `Accept: application/json` and a bearer token on every call.
- [ ] `POST /results/compile` accepts only `enrollment_id`, `class_subject_id` and `term_id` -
      never send a calculated `percentage`, `grade`, `grade_point` or `status`; they are
      silently ignored.
- [ ] Recompile by sending the exact same triple again - there is no separate "update" call,
      and doing so is always safe: the same row updates, never a duplicate.
- [ ] Expect `status: "INCOMPLETE"` with `grade`/`grade_point`/`remark` all `null` while any
      configured assessment is still unscored - this is not an error.
- [ ] Read `POST /results/bulk`'s per-row `error` field, not just the HTTP status - a 200
      response can still carry individual `INCOMPLETE` rows and per-row errors side by side.
- [ ] Do not try to `POST`/`PUT`/`PATCH`/`DELETE` `/results/{id}` directly - all four are `405`.
      A result changes only by recompiling through §3.2/§3.3.
- [ ] Do not assume every result has a grade - check for `null` explicitly before displaying
      one, whether because the result is incomplete, no grading scale is configured, or the
      percentage falls in a gap.
- [ ] `GET /scores?enrollment_id=...&subject_id=...&term_id=...` (Module 10) is where the
      assessment-level breakdown behind a result lives - this module's own resource never
      repeats it.
