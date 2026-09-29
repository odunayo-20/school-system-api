# Report Cards API

A structured, read-only presentation of a student's finalized subject results.

Module 14. Two `GET` endpoints, no new table, and one governing rule: **this module never
calculates a result. Every figure it renders is read directly off Module 12's `Result` rows
exactly as Module 13 left them.**

- [0. Read this first: what a report card is, and what this module does not do](#0-read-this-first-what-a-report-card-is-and-what-this-module-does-not-do)
- [1. Conventions](#1-conventions)
- [2. The report card resource](#2-the-report-card-resource)
- [3. Endpoints](#3-endpoints)
- [4. Availability: only finalized results appear](#4-availability-only-finalized-results-appear)
- [5. The summary](#5-the-summary)
- [6. Permissions](#6-permissions)
- [7. Client checklist](#7-client-checklist)

---

## 0. Read this first: what a report card is, and what this module does not do

```
Enrollment (Amina Yusuf, JSS 1, 2025/2026) + Term (First Term)
  → every PUBLISHED or LOCKED Result for that pair, one per subject
  → Report Card: subjects[], summary{}
```

A **report card** is the aggregate of every finalized `Result` row (Module 12/13) sharing one
`enrollment_id` and one `term_id` - never a second, independent calculation of a student's
performance. There is no `report_cards` table: the audit behind this module found no
independent, persistent data a report card needs that does not already belong to `Result` -
see the [Module 14 audit](../audits/module-14-report-cards-audit.md) §2 for the full reasoning.

**This module does not implement Report Cards' own workflow.** A report card has no `DRAFT`/
`SUBMITTED`/`APPROVED` states of its own - it simply reads whatever the underlying `Result`
rows' own status (Module 13) already says. See §4.

**This module does not implement:** per-assessment breakdown (call
[`GET /scores`](score-management.md) with the same `enrollment_id`/`subject_id`/`term_id`
filters, exactly as [`result-compilation.md`](result-compilation.md) already directs), class
position/ranking, teacher or principal comments, attendance, report-card printing/PDF export,
Promotion, or a public Result Checker. None of these has a genuine requirement or existing data
behind it in this project today - each is documented as a deliberate omission, not an
oversight, in the audit.

---

## 1. Conventions

Inherited from the [API reference](README.md): bearer tokens, `Accept: application/json`, the
`{ "data": ..., "message": ... }` envelope, `meta`/`links` as siblings of `data`, and
200/401/403/404/405/422/429.

### 1.1 Read-only

Both endpoints are `GET`. `POST`/`PUT`/`PATCH`/`DELETE` against either path are **405** - a
report card has nothing of its own to create, amend or delete.

### 1.2 A missing report card is `404`, not an empty `200`

Unlike a list endpoint (which answers "nothing matched" with `data: []`), the single-card
endpoint is a singular lookup: when an enrollment/term pair has no finalized result at all -
because nothing has been published yet, or because the two do not belong to the same academic
session - it answers **404**, matching every other singular `GET /{resource}/{id}` in this API.

---

## 2. The report card resource

```json
{
  "data": {
    "enrollment": { "id": 12, "student": { "...": "..." }, "academic_session": { "...": "..." }, "school_class": { "...": "..." }, "section": { "...": "..." } },
    "term": { "id": 4, "name": "First Term", "academic_session": { "...": "..." } },
    "subjects": [
      {
        "result_id": 41,
        "class_subject": { "id": 7, "school_class": { "...": "..." }, "subject": { "id": 3, "name": "Mathematics", "...": "..." } },
        "percentage": "90.00",
        "grade": "A",
        "grade_point": "5.00",
        "remark": "Excellent",
        "status": "PUBLISHED"
      }
    ],
    "summary": {
      "subjects_count": 2,
      "overall_percentage": "70.00",
      "average_grade_point": "4.00"
    }
  }
}
```

`enrollment` reuses [`EnrollmentResource`](enrollment-management.md) wholesale (already nesting
student, academic session, class and section) rather than flattening those four pieces here a
second time - the identical "no second, divergent field list" discipline every resource in this
API already follows for its own nested records.

Each `subjects` row carries `result_id` - the underlying `Result`'s own id - so a caller who
also holds `results.view` can cross-reference `GET /results/{id}` for that subject's full
workflow metadata (`submitted_by`, `approved_at`, etc.), rather than this module repeating it.

**No per-assessment breakdown, and no `comments` key.** See §0.

---

## 3. Endpoints

| Method | Path | Permission |
|---|---|---|
| `GET` | `/report-cards/enrollments/{enrollment}/terms/{term}` | `report_cards.view` |
| `GET` | `/report-cards/students/{student}` | `report_cards.view` |

Both require an active bearer token (`auth:api` + `active`).

### 3.1 `GET /report-cards/enrollments/{enrollment}/terms/{term}`

The full report card for one enrollment, one term.

```bash
curl -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  http://localhost:8000/api/v1/report-cards/enrollments/12/terms/4
```

Reuses two **existing** identifiers - `enrollment_id` and `term_id`, the same pair
`results.enrollment_id`/`results.term_id` already key on - rather than a third invented "report
card id," or `student_id`+`academic_session_id` the way a student's identity alone never safely
identifies a placement (the same reasoning every module since Module 06 already applies to its
own references).

**200** with every finalized subject, or **404** - see §1.2 and §4.

### 3.2 `GET /report-cards/students/{student}`

Every term across this student's whole enrollment history with at least one finalized report
card, newest first - a lightweight summary row per term, not the full subject breakdown (call
§3.1 with the `enrollment`/`term` ids this row carries for that).

```bash
curl -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  http://localhost:8000/api/v1/report-cards/students/8
```

```json
{
  "data": [
    {
      "enrollment": { "...": "..." },
      "term": { "id": 4, "name": "First Term", "...": "..." },
      "subjects_count": 8,
      "overall_percentage": "76.40",
      "average_grade_point": "4.10"
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 15, "from": 1, "to": 1, "total": 1 },
  "links": { "...": "..." }
}
```

| Parameter | Values | Notes |
|---|---|---|
| `per_page` | 1-100, default 15 | |

No filters beyond paging (§9 of the brief this module answers: a student's whole report-card
history is small enough - bounded by terms-per-session × sessions-enrolled - that session/term
filtering was judged not genuinely needed). There is no `search`.

**200** with `data: []` (never a 404) when the student exists but has no finalized report card
yet, or has no enrollment at all - matching this project's own established "empty list, not an
error" convention for every other list endpoint. **404** only if the `{student}` id itself does
not exist.

---

## 4. Availability: only finalized results appear

A subject appears on a report card **only** once its `Result` is `PUBLISHED` or `LOCKED`
(Module 13). `INCOMPLETE`, `COMPILED`, `SUBMITTED` and `APPROVED` results are invisible here -
not shown as "pending," simply absent - applied identically to every caller, including
`SUPER_ADMIN`. There is no separate "preview a draft report card" mode: an administrator wanting
to see in-progress data uses [`GET /results`](result-compilation.md), which already exposes
every status: `report_cards.*` is specifically the finalized presentation layer, and blurring
that line by offering a draft-preview mode inside it was a deliberate simplification - see the
audit §4.

A term whose subjects are a mix of finalized and not-yet-finalized results shows **only** the
finalized ones; `summary.subjects_count` counts exactly those.

---

## 5. The summary

Two derived values, both a plain, documented arithmetic mean over already-authoritative
per-subject figures - **never** a second application of Module 09's weighting or Module 11's
grading:

- **`overall_percentage`** - the unweighted mean of every included subject's own `percentage`.
  Every subject counts equally: this project has no subject-credit or subject-weight concept
  anywhere in its schema, so an unweighted mean is the only default that introduces no new
  policy decision this module was never asked to make.
- **`average_grade_point`** - the mean of every included subject's `grade_point`, **excluding**
  subjects where it is `null` (no grading scale configured for the class level, or the
  percentage fell in a gap no band covers) - never treated as zero, the identical missing-data
  discipline Module 12 itself established for a single subject. `null` when no included subject
  has a `grade_point` at all.

**There is deliberately no `overall_grade`.** Re-running the averaged percentage back through a
grading scale was considered and rejected: nothing in this project defines that a class-level
grading scale is meant to interpret anything other than one subject's own percentage. See the
audit §12 for the full reasoning, and why **class position/ranking is likewise not
implemented** - no ranking methodology, tie-handling rule, or scope (class vs. section) is
defined anywhere in this project's existing requirements, so building one would mean inventing
policy this module has no authority to set. Both are documented here as explicit, deliberate
gaps for a future module to fill once that policy exists, not silent omissions.

---

## 6. Permissions

| Permission | Grants |
|---|---|
| `report_cards.view` | Both endpoints |

**No `.create`/`.update`/`.delete`** - a report card is read-only.

### 6.1 Role grants

| Role | `report_cards.view` |
|---|:--:|
| `SUPER_ADMIN` | ✓ |
| `ADMIN` | ✓ |
| `REGISTRAR` | ✓ |
| `STAFF` | — |
| `STUDENT` | ✓ (own report card only) |

**`STAFF` holds nothing here at all** - a deliberate departure from `results.*`/`scores.*`
(`STAFF` holds both, scoped per class subject). A report card spans **every** subject in a term,
but this project's only teacher-scope primitive (`TeacherAssignment`) is scoped to one class
subject at a time; there is no "form teacher"/"class teacher" concept to safely widen that
scope into a whole-term view. Granting `STAFF` access here would either leak subjects a teacher
was never assigned to teach, or require inventing a scope this project's architecture does not
support - see the audit §7.

**`STUDENT` holds `report_cards.view`** - the first business-domain permission any role has ever
held over academic data about *themselves*, beyond Module 01's bare `profile.*` pair. Every
request is additionally scoped in the service: a `STUDENT` caller may only reach an
enrollment/student that is their own (`user->student->id === enrollment->student_id`) - the
identical "coarse permission, fine-grained service scope" layering `STAFF` already gets over
`results.*`. An attempt to view or list another student's report card is **403**, never a
different student's data. This is deliberately narrower than the "Result Checker" module every
earlier module's own docs deferred student access to: that module (unauthenticated, a
PIN/reference-code lookup) is a different mechanism for a different audience; this is the
authenticated student's own account reading their own already-`PUBLISHED`/`LOCKED` record - see
the audit §7.

---

## 7. Client checklist

- [ ] Send `Accept: application/json` and a bearer token on every call.
- [ ] A **404** from `GET /report-cards/enrollments/{enrollment}/terms/{term}` can mean the ids
      don't exist, don't belong to each other, or simply have no finalized result yet - the
      three are not distinguished, matching this project's own single 404 message convention.
- [ ] `GET /report-cards/students/{student}` returns `data: []` (never a 404) for a student
      with no finalized report card yet - check the array length, not the status code, to tell
      "nothing yet" from an error.
- [ ] Do not expect an `overall_grade` or a class position - neither is implemented; see §5 for
      why, and build your own UI around `overall_percentage`/`average_grade_point` (both
      nullable-aware) instead.
- [ ] For the per-assessment breakdown behind a subject's percentage, call
      `GET /scores?enrollment_id=...&subject_id=...&term_id=...` (Module 10) - this module's
      own resource never repeats it.
- [ ] A `STUDENT` account can only ever reach their own report card and history - build a
      student-facing UI that never asks them for someone else's enrollment or student id.
- [ ] Do not try to `POST`/`PUT`/`PATCH`/`DELETE` either endpoint - both are `405`.
