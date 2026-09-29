# Score Management API

What a specific student obtained against a specific configured assessment.

Module 10. Six endpoints, one table, and a scope check no earlier module needed: **every read
and write is additionally restricted to the acting user, on top of the usual permission gate.**

- [0. Read this first: why a score references an enrollment, not a student](#0-read-this-first-why-a-score-references-an-enrollment-not-a-student)
- [1. Conventions](#1-conventions)
- [2. The score resource](#2-the-score-resource)
- [3. Endpoints](#3-endpoints)
- [4. Teacher assignment scope](#4-teacher-assignment-scope)
- [5. Permissions](#5-permissions)
- [6. Client checklist](#6-client-checklist)

---

## 0. Read this first: why a score references an enrollment, not a student

```
Assessment (Module 09)                        Score
"Mathematics, First Term, JSS 2, CA 1"   →    references BOTH
                                                    assessment_id
Enrollment (Module 06)                             enrollment_id
"Student 123's placement in JSS 2, 2026/2027"
```

A score references `enrollment_id`, **not** `student_id`. A student accumulates one
`Enrollment` row per academic session (JSS 1 in 2025/2026, JSS 2 in 2026/2027, ...), and a
score belongs to the SPECIFIC placement the assessment falls in - a JSS 1 score can never
surface under the student's later JSS 2 enrollment, because it is never linked to the student
directly at all. `student_id` is still reachable (`enrollment.student`), and remains a valid
list filter, but it is never a column on this table.

**This module holds no `max_score`, `percentage`, `grade`, or `student_id` column.** `max_score`
is read live from `assessment.max_score` on every write - never trusted from the client, never
copied here - and `score_percentage` is computed at read time from the same live value. There is
no grading, no letters, no GPA, no pass/fail and no report card here: this module stores a raw
mark, Module 11+ interprets it.

---

## 1. Conventions

Inherited from the [API reference](README.md): bearer tokens, `Accept: application/json`, the
`{ "data": ..., "message": ... }` envelope, `meta`/`links` as siblings of `data`, and
200/201/401/403/404/405/422/429.

### 1.1 There is no delete endpoint

`DELETE /scores/{id}` is not registered - **405** with `Allow: GET, HEAD, PUT`. A score is
exactly the kind of academic-history anchor a future grading/result module will reference. A
mistaken mark is corrected through the ordinary `PUT`, not erased.

### 1.2 `PUT` never touches `assessment_id` or `enrollment_id`

Both are Store-only - not merely unvalidated on `PUT`, structurally absent from the request. The
pair a score names is fixed for its lifetime; there is no "reassign this score to a different
student or assessment" operation, on purpose - see §4.

### 1.3 Every read and write is additionally SCOPED, beyond the permission gate

A holder of `scores.create` is not thereby entitled to score every class subject in the school.
See §4.

---

## 2. The score resource

```json
{
  "data": {
    "id": 12,
    "score": "17.00",
    "max_score": "20.00",
    "score_percentage": 85.0,
    "remarks": null,
    "assessment": { "id": 4, "name": "CA 1", "class_subject": { "...": "..." }, "term": { "...": "..." }, "assessment_type": { "...": "..." } },
    "enrollment": { "id": 9, "student": { "...": "..." }, "academic_session": { "...": "..." }, "school_class": { "...": "..." }, "section": { "...": "..." } },
    "created_at": "2026-10-04T09:05:00+00:00",
    "updated_at": "2026-10-04T09:05:00+00:00"
  },
  "message": "..."
}
```

`max_score` and `score_percentage` are derived from the loaded assessment, not stored columns -
`score_percentage` is `null`, never a division-by-zero error, if `max_score` is ever not a
positive number (unreachable through the API today, guarded anyway). Every related record is
rendered through its own module's resource - `AssessmentResource`, `EnrollmentResource` (itself
nesting `StudentResource`) - so this module maintains no second, divergent field list for any of
them.

---

## 3. Endpoints

| Method | Path | Permission |
|---|---|---|
| `GET` | `/scores` | `scores.view` |
| `POST` | `/scores` | `scores.create` |
| `POST` | `/scores/bulk` | `scores.create` |
| `GET` | `/scores/{id}` | `scores.view` |
| `PUT` | `/scores/{id}` | `scores.update` |

All five require an active bearer token (`auth:api` + `active`), and all five are additionally
scoped per §4.

### 3.1 `GET /scores`

| Parameter | Values | Notes |
|---|---|---|
| `assessment_id` | integer | Must exist |
| `assessment_type_id` | integer | Must exist |
| `enrollment_id` | integer | Must exist |
| `student_id` | integer | Must exist - resolved through the enrollment |
| `school_class_id` | integer | Must exist - resolved through the enrollment |
| `section_id` | integer | Must exist - resolved through the enrollment |
| `subject_id` | integer | Must exist - resolved through the assessment's class subject |
| `academic_session_id` | integer | Must exist - resolved through the enrollment |
| `term_id` | integer | Must exist - resolved through the assessment |
| `per_page` | 1–100, default 15 | |

**There is no `search`** - a score holds no text field of its own; `?search=...` is rejected
with `422`.

**A filter narrows within your own scope; it never widens it.** A teacher sending
`?student_id=999` for a student outside their own assigned class subjects gets an empty list,
never that student's data - see §4.

### 3.2 `POST /scores`

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"assessment_id":4,"enrollment_id":9,"score":17}' \
  http://localhost:8000/api/v1/scores
```

**201.**

**Every reference is checked against the database, never trusted from the client:**

- `assessment_id` must exist and be `ACTIVE`, **and its class subject, class and class level
  must all independently be active** - the identical three-level chain Module 08 and Module 09
  both apply to their own class-hierarchy references.
- `enrollment_id` must exist and be `ACTIVE` (not `WITHDRAWN`/`CANCELLED`).
- **The enrollment must share the assessment's own class and academic session.** An enrollment
  in a different class, or a different session (a student's earlier JSS 1 placement against a
  JSS 2 assessment), is refused with `422` naming the mismatch.
- **`score` must not exceed the assessment's live `max_score`, and may not be negative.** The
  ceiling is read from the database on every request; nothing you send (e.g. a
  `maximum_score` field of your own) is ever read as the limit. Decimal scores (`17.5`) are
  supported.
- **At most one score per assessment per enrollment.** A second `POST` for the same pair is
  refused with `422` naming `enrollment_id`. Enforced twice: a scoped `Rule::unique` at
  validation, and a database unique index as the race-safe backstop.

**Term completion does not block this.** Unlike a new enrollment or assignment, a score is
retrospective entry for an assessment that already happened - routinely finished right as or
after a term closes.

### 3.3 `POST /scores/bulk`

One assessment, many students - the shape a class roster is actually entered in.

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"assessment_id":4,"scores":[{"enrollment_id":9,"score":17},{"enrollment_id":10,"score":14}]}' \
  http://localhost:8000/api/v1/scores/bulk
```

**201** with an array of created scores under `data`, in the same shape `GET /scores` returns.

**All-or-nothing.** Every row is validated - the same rules `POST /scores` applies, per row -
**before anything is written**. If any row is invalid, the WHOLE batch is refused with `422` and
every row's own error, keyed by index (`scores.0.score`, `scores.2.enrollment_id`, ...); nothing
is saved, including the rows that were individually valid. Only once every row passes does a
single transaction insert the batch.

| Rule | Detail |
|---|---|
| At least 1, at most 100 rows | `scores` |
| No enrollment repeated within the batch | `scores.*.enrollment_id` - `distinct` |
| Every enrollment must exist, be `ACTIVE`, share the assessment's class and session | same context check as the single-score path |
| No enrollment already scored for this assessment | `scores.*.enrollment_id` - `unique` |
| Every score bounded by the SAME assessment's live `max_score` | `scores.*.score` |

### 3.4 `PUT /scores/{id}`

Accepts `score` and `remarks`. `assessment_id` and `enrollment_id` are **not** accepted here at
all - see §1.2. `score` is re-validated against the assessment's CURRENT `max_score`, not the
value in effect when the score was first created (Module 09's `Assessment.max_score` is itself
editable; a score already on file is never retroactively invalidated by a later change, only
re-checked on its next write).

---

## 4. Teacher assignment scope

This is the one genuinely new authorization shape in the API. Every other module's permission
grant is the whole answer once held; **`scores.*` is not**, for teaching staff specifically.

| Actor | Scope |
|---|---|
| `SUPER_ADMIN`, `ADMIN` | Unrestricted - every score in the school |
| `REGISTRAR` | Unrestricted, **view only** |
| `STAFF`, `staff_type: TEACHING`, actively employed | **Only** scores for class subjects they hold an ACTIVE `TeacherAssignment` for, **for the same academic session as the assessment itself** |
| `STAFF`, `staff_type: NON_TEACHING` | Nothing, even though the STAFF role holds `scores.*` - see below |
| `STUDENT` | Nothing in this module - see §5 |

**Teacher A teaches JSS 2 Mathematics. Teacher A cannot submit JSS 2 English scores merely by
knowing an assessment id.** `POST /scores`, `POST /scores/bulk`, `PUT /scores/{id}`, `GET
/scores/{id}` and `GET /scores` are ALL checked against the acting teacher's own
`TeacherAssignment` rows - not only the assessment named in a payload, but the row itself when
reading or amending one by id. An unrelated teacher naming a real score's id gets `403`, not the
score.

**The check is a (class subject, academic session) PAIR, not the class subject alone.**
`TeacherAssignment` is itself session-scoped (Module 08) - a teacher assigned to JSS 2
Mathematics in 2025/2026 is not thereby authorized for a 2026/2027 assessment of the same class
subject if someone else holds that responsibility this year.

**`STAFF` holds `scores.*` at the role level, unlike Module 08's `teacher_assignments.*`.**
Entering a score is a teaching duty performed BY staff, not an administrative decision ABOUT
staff - the role must hold the permission for a teacher to reach the endpoint at all. The
permission is not the whole answer, though: a `NON_TEACHING` staff member holds it too, and is
refused by the scope check above on every actual attempt (an empty list on `GET /scores`, `403`
on any write) - the permission grants the ability to ATTEMPT the operation; the scope decides
whether a specific attempt is valid.

---

## 5. Permissions

| Permission | Grants |
|---|---|
| `scores.view` | `GET /scores`, `GET /scores/{id}` |
| `scores.create` | `POST /scores`, `POST /scores/bulk` |
| `scores.update` | `PUT /scores/{id}` |

**No `scores.delete`** - no delete endpoint is registered. **No separate bulk permission** -
recording many students' marks in one call is the same capability as recording one.

### 5.1 Role grants

| Role | `view` | `create` (incl. bulk) | `update` |
|---|:--:|:--:|:--:|
| `SUPER_ADMIN` | ✓ | ✓ | ✓ |
| `ADMIN` | ✓ | ✓ | ✓ |
| `REGISTRAR` | ✓ | — | — |
| `STAFF` | ✓ (scoped) | ✓ (scoped) | ✓ (scoped) |
| `STUDENT` | — | — | — |

**`REGISTRAR` holds `view` only** - matching Module 08's split for `teacher_assignments.*`
rather than Module 09's split for `assessments.*`: entering or correcting a score is a teaching
act, the same staffing/teaching-domain territory Module 08 already withholds create/update
access to from `REGISTRAR`.

**`STUDENT` holds nothing.** Raw, pre-grading marks are working data for teachers; exposing them
to the student they belong to - without grading, moderation, or the framing a report card
gives - belongs to a future Result Checker module, not this one.

---

## 6. Client checklist

- [ ] Send `Accept: application/json` and a bearer token on every call.
- [ ] Never send a `maximum_score` (or similar) field - nothing reads one. The ceiling always
      comes from the named assessment's own, live `max_score`.
- [ ] Expect `422` naming `enrollment_id` if that enrollment already has a score for this
      assessment.
- [ ] Expect `422` if the enrollment's class or academic session does not match the
      assessment's own - a student's earlier enrollment cannot be scored against a later
      assessment, or vice versa.
- [ ] As teaching staff, expect `403` on any assessment outside your own active assignments -
      including reading or amending an existing score by id, not only creating a new one.
- [ ] For a whole class roster, prefer `POST /scores/bulk` over one `POST /scores` per student -
      it is all-or-nothing, so a single bad row never leaves the roster half-entered.
- [ ] A `422` from `/scores/bulk` names every bad row by index (`scores.N.field`) - fix all of
      them and resubmit the whole batch; nothing from a rejected batch was saved.
- [ ] Do not try to `DELETE` or `PATCH` a score. Both are `405`.
- [ ] Do not send `assessment_id` or `enrollment_id` to `PUT /scores/{id}` expecting them to
      change - they have no key to send.
- [ ] Do not send `search` to `GET /scores` - filter by `assessment_id`, `enrollment_id`,
      `student_id`, `school_class_id`, `section_id`, `subject_id`, `academic_session_id` or
      `term_id` instead.
