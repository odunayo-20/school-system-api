# Student Promotion API

Recording what happens to a student's placement going into a new academic session: **PROMOTED**,
**RETAINED**, **GRADUATED** or **NOT_ELIGIBLE** - never a silent update to the student's own
record.

Module 15. One new table (`promotions`), one write endpoint, two read endpoints, and one
governing rule: **a promotion decision creates a new `Enrollment` row (or none at all); it never
mutates the source enrollment or gives `Student` a mutable "current class" column.**

- [0. Read this first: why promotion is a new enrollment, not a class-id update](#0-read-this-first-why-promotion-is-a-new-enrollment-not-a-class-id-update)
- [1. Conventions](#1-conventions)
- [2. The promotion resource](#2-the-promotion-resource)
- [3. Endpoints](#3-endpoints)
- [4. The four decisions](#4-the-four-decisions)
- [5. Validation](#5-validation)
- [6. Idempotency](#6-idempotency)
- [7. Historical safety](#7-historical-safety)
- [8. Permissions](#8-permissions)
- [9. Client checklist](#9-client-checklist)

---

## 0. Read this first: why promotion is a new enrollment, not a class-id update

```
Student -> (current) Enrollment: JSS 2 A, 2025/2026
                |
                v
        Promotion decision: PROMOTED, target session 2026/2027
                |
                v
Student -> (new) Enrollment: JSS 3 A, 2026/2027   <- the OLD enrollment is untouched
```

**This is never implemented as `$student->class_id = $nextClass; $student->save();`.** There is
no `current_class_id` column on `Student` (Module 04) and none was added for this module.
`Enrollment` (Module 06) is, and remains, the sole authoritative record of where a student sits
in any given academic session - promotion is an academic-history EVENT recorded against it, not
a mutation of it. Promoting or retaining a student creates a genuinely **new** `Enrollment` row
for the target session, through the **existing** `EnrollmentService::create()` (Module 06) -
never a second insert path. The source enrollment's `status`, `school_class_id` and `section_id`
are never written to by this module, under any decision.

**Graduating or marking a student not-yet-eligible produces no target enrollment at all** - there
is no placement to create. A genuinely new table, `promotions`, is what makes these two decisions
permanently recordable even though neither results in an `Enrollment` row. See the
[Module 15 audit](../audits/module-15-student-promotion-audit.md) §1 for why an enrollment-only
design was rejected.

**This module does not implement:** an automatic promotion formula (no `percentage >= 50` rule,
or any other computed pass/fail threshold), a class-progression graph (there is no "next class"
lookup anywhere in this schema), bulk/batch promotion, or a reject/undo transition for an
already-recorded decision. Each is a deliberate, documented omission - see the audit.

---

## 1. Conventions

Inherited from the [API reference](README.md): bearer tokens, `Accept: application/json`, the
`{ "data": ..., "message": ... }` envelope, `meta`/`links` as siblings of `data`, and
200/201/401/403/404/405/422/429.

---

## 2. The promotion resource

```json
{
  "data": {
    "id": 7,
    "source_enrollment": { "id": 12, "student": { "...": "..." }, "academic_session": { "...": "..." }, "school_class": { "...": "..." }, "section": { "...": "..." } },
    "target_academic_session": { "id": 5, "name": "2026/2027", "...": "..." },
    "target_enrollment": { "id": 19, "academic_session": { "...": "..." }, "school_class": { "...": "..." }, "section": { "...": "..." } },
    "decision": "PROMOTED",
    "reason": null,
    "decided_by": { "id": 3, "name": "Ada Obi" },
    "decided_at": "2026-07-31T10:00:00+00:00",
    "created_at": "2026-07-31T10:00:00+00:00",
    "updated_at": "2026-07-31T10:00:00+00:00"
  }
}
```

`source_enrollment` and `target_enrollment` both reuse
[`EnrollmentResource`](enrollment-management.md) wholesale - the same "no second, divergent field
list" discipline every resource in this API already follows for a nested record.
`target_enrollment` is `null` for **GRADUATED** and **NOT_ELIGIBLE** (§4). `decided_by` is
rendered as the minimal `{id, name}` projection every other module's own "who acted" field
already uses (`ResultResource`'s `submitted_by`/`approved_by`, etc.), never a full user object -
and is `null` if the deciding account has since been deleted (see §7).

---

## 3. Endpoints

| Method | Path | Permission |
|---|---|---|
| `POST` | `/students/{student}/promote` | `promotions.create` |
| `GET` | `/promotions` | `promotions.view` |
| `GET` | `/promotions/{promotion}` | `promotions.view` |

All three require an active bearer token (`auth:api` + `active`).

### 3.1 `POST /students/{student}/promote`

Records one promotion decision for one student.

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "source_enrollment_id": 12,
    "target_academic_session_id": 5,
    "decision": "PROMOTED",
    "target_school_class_id": 9,
    "target_section_id": 21
  }' \
  http://localhost:8000/api/v1/students/8/promote
```

| Field | Rules |
|---|---|
| `source_enrollment_id` | required, must be an **ACTIVE** enrollment belonging to `{student}` |
| `target_academic_session_id` | required, must exist and not be `COMPLETED` |
| `decision` | required, one of `PROMOTED`, `RETAINED`, `GRADUATED`, `NOT_ELIGIBLE` |
| `target_school_class_id` | required for `PROMOTED`, **prohibited** for every other decision |
| `target_section_id` | required for `PROMOTED`, **prohibited** for every other decision; must belong to `target_school_class_id` |
| `reason` | optional, up to 1000 characters, never mandatory |

**201** with the created promotion, or **422** - see §5.

### 3.2 `GET /promotions`

```bash
curl -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  "http://localhost:8000/api/v1/promotions?decision=PROMOTED&student_id=8"
```

| Parameter | Values |
|---|---|
| `student_id` | integer, an existing student |
| `source_enrollment_id` | integer |
| `target_academic_session_id` | integer |
| `decision` | one of the four decisions |
| `per_page` | 1-100, default 15 |

No `search` - a promotion has no free-text field worth indexing beyond the optional `reason`.

### 3.3 `GET /promotions/{promotion}`

**200** with the single promotion, or **404**.

---

## 4. The four decisions

| Decision | Creates a target enrollment? | Effect on `Student.status` |
|---|:--:|---|
| `PROMOTED` | Yes - target class/section is the caller's explicit choice | unchanged |
| `RETAINED` | Yes - target class/section is always **copied from the source enrollment**, never client-supplied | unchanged |
| `GRADUATED` | No | set to `GRADUATED` (terminal) |
| `NOT_ELIGIBLE` | No | unchanged |

**There is no automatic decision.** All four are explicit, human-recorded facts - this module
computes none of them from a score, percentage or attendance figure. There is also no
class-progression lookup: `PROMOTED`'s target class/section are always named by the caller,
because nothing in this project's schema configures a "next class" (no `next_class_id`/
`next_class_level_id` anywhere on `SchoolClass` or `ClassLevel`). `RETAINED` is the one
decision where the target is not a fresh choice - the student's own current class and section,
carried forward unchanged into the new session - and sending `target_school_class_id`/
`target_section_id` for `RETAINED` is a **422**, not a silently-ignored value.

`PROMOTED` must target a genuinely **different** class than the source enrollment - promoting a
student "into" their own current class is rejected; record it as `RETAINED` instead.

`GRADUATED` is **terminal**: once recorded, `Student.status` becomes `GRADUATED` and any further
promotion decision for that student is refused (the same terminal-record guard
`StudentService::update()` already applies). `NOT_ELIGIBLE` is **not** terminal - it is a
standing record that this student was not moved forward this round, for a reason this module has
no visibility into; a later decision for the same source enrollment, against a different target
session, remains possible.

---

## 5. Validation

Every reference is re-verified server-side, never trusted merely because two ids arrived in the
same request:

- `source_enrollment_id` must be **ACTIVE** and must genuinely belong to `{student}` - naming a
  real, ACTIVE enrollment that belongs to someone else is a **422** validation failure, backed by
  an identical service-layer check as defense in depth.
- `target_academic_session_id` must exist, must not be `COMPLETED`, must differ from the source
  enrollment's own session, and must start **after** it (compared by `start_date`, the same
  chronological ordering `AcademicSession::scopeOrderByRecency()` already uses) - a promotion
  "into" the same session, or backwards into an earlier one, is rejected.
- `target_school_class_id`/`target_section_id` (PROMOTED only) must both be **ACTIVE**, and the
  section must genuinely belong to the named class.
- A student who has already left the school (`GRADUATED`/`WITHDRAWN`) cannot receive a further
  promotion decision.

---

## 6. Idempotency

Two database-level layers, not merely an application check:

- **PROMOTED/RETAINED**: the pre-existing `enrollments.unique(student_id, academic_session_id)`
  index (Module 06) refuses a second placement for the same student and target session - the
  identical constraint any unrelated duplicate-enrollment attempt already hits.
- **GRADUATED/NOT_ELIGIBLE**: neither creates an enrollment, so a new
  `promotions.unique(source_enrollment_id, target_academic_session_id)` index is this table's own
  backstop - "at most one promotion decision per placement, per target session."

Both are translated into the same **422** business-rule response a client already recognizes from
every other module's own duplicate-request handling; a repeated call never produces a second row,
and never corrupts state already written (verified against a simulated concurrent-request race).

---

## 7. Historical safety

- The **source enrollment is never mutated** by any decision - its `status`, `school_class_id`
  and `section_id` remain exactly as they were, forever queryable through
  [`GET /enrollments/{id}`](enrollment-management.md).
- **Results and report cards already compiled against the source enrollment are untouched** -
  this module writes nothing to `results` or any table Module 12-14 own, and reads nothing from
  them either.
- **Future results use the new enrollment only.** Once a `PROMOTED`/`RETAINED` decision creates a
  target enrollment, any result compiled for the student's new class/session is recorded against
  that new `enrollment_id` - the old one is not retroactively touched.
- `decided_by` is `nullOnDelete`: the historical fact that someone made this decision outlives the
  specific user account that made it, matching Module 13's own `submitted_by`/`approved_by`
  precedent.

---

## 8. Permissions

| Permission | Grants |
|---|---|
| `promotions.view` | `GET /promotions`, `GET /promotions/{promotion}` |
| `promotions.create` | `POST /students/{student}/promote` |

### 8.1 Role grants

| Role | `promotions.view` | `promotions.create` |
|---|:--:|:--:|
| `SUPER_ADMIN` | ✓ | ✓ |
| `ADMIN` | ✓ | ✓ |
| `REGISTRAR` | ✓ | ✓ |
| `STAFF` | — | — |
| `STUDENT` | — | — |

**`STAFF` and `STUDENT` hold nothing here at all** - a deliberate departure from `scores.*` and
`results.*` (`STAFF` holds both, scoped per class subject). Promotion is a whole-of-school
academic-year decision, not a per-subject one; this project's only teacher-scope primitive
(`TeacherAssignment`) has no equivalent scope to safely widen into "may promote this student."
There is no student-facing report-card-style self-access grant either: a promotion decision is an
administrative act about a student, not a record a student reads about themselves the way a
report card is.

---

## 9. Client checklist

- [ ] Send `Accept: application/json` and a bearer token on every call.
- [ ] `source_enrollment_id` must be an ACTIVE enrollment that genuinely belongs to the
      `{student}` in the URL - a real enrollment belonging to someone else is a **422**, not a
      403 (it is caught by validation, before authorization-style IDOR logic would even run).
- [ ] Never send `target_school_class_id`/`target_section_id` for anything other than `PROMOTED`
      - they are **prohibited**, not merely optional, for `RETAINED`/`GRADUATED`/`NOT_ELIGIBLE`.
- [ ] A `PROMOTED` decision targeting the student's own current class is rejected - use
      `RETAINED` for "stays in the same class."
- [ ] `GRADUATED` is terminal - expect every further promotion attempt for that student to fail
      once graduated.
- [ ] `NOT_ELIGIBLE` is not terminal - the same source enrollment can receive a later decision
      against a different target session.
- [ ] A repeated call with the same `source_enrollment_id`/`target_academic_session_id` pair is
      always a **422**, never a silent success and never a duplicate row.
- [ ] There is no bulk/batch promotion endpoint and no undo/reject transition - each decision is
      recorded individually and is permanent once created.
