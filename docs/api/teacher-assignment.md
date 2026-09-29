# Teacher, Class & Subject Assignment API

Which teaching staff member is responsible for which class subject, for one academic session.

Module 08. Seven endpoints, one table, and a boundary that matters more than the endpoints do:
**a Teacher Assignment is session-scoped even though the Class Subject it names is not.**

- [0. Read this first: why this table has a session and Class Subject does not](#0-read-this-first-why-this-table-has-a-session-and-class-subject-does-not)
- [1. Conventions](#1-conventions)
- [2. The assignment resource](#2-the-assignment-resource)
- [3. Endpoints](#3-endpoints)
- [4. Lifecycle and reassignment](#4-lifecycle-and-reassignment)
- [5. Permissions](#5-permissions)
- [6. Client checklist](#6-client-checklist)

---

## 0. Read this first: why this table has a session and Class Subject does not

```
Staff (staff_type = TEACHING)  →  Teacher Assignment  →  Class Subject  →  Class + Subject
                                          ↑
                                   academic_session_id
```

A **Class Subject** ("JSS 2 teaches Mathematics") is a standing curriculum fact - Module 07
gave it no session at all. **Who teaches it is not standing** - the same person rarely holds
the same responsibility forever, and this module's own brief describes exactly that: Teacher A
this year, Teacher B next (or mid-year). A **Teacher Assignment** is therefore the session-scoped
fact this project was missing: a specific teacher, responsible for a specific class subject, for
a specific academic session - mirroring `Enrollment`'s own relationship to `SchoolClass` exactly
(a session-scoped placement into an otherwise-standing structure).

There is **no** `Teacher` model and **no** `TEACHER` user role. `teaching_staff_id` references
`staff.id` directly; eligibility means `Staff.staff_type === TEACHING` and
`Staff.status === ACTIVE` (`EmploymentStatus`), checked server-side on every write.

---

## 1. Conventions

Inherited from the [API reference](README.md): bearer tokens, `Accept: application/json`, the
`{ "data": ..., "message": ... }` envelope, `meta`/`links` as siblings of `data`, and
200/201/401/403/404/405/422/429.

### 1.1 There is no delete endpoint

`DELETE /teacher-assignments/{id}` is not registered - **405** with `Allow: GET, HEAD, PUT`. An
assignment is exactly the kind of academic-history anchor a future assessment, score and
result chain will reference to answer "who taught this subject, and when" -
`POST .../cancel` is the record-preserving replacement for a mistaken entry.

### 1.2 `PUT` touches only `notes`

Unlike every other field, `teaching_staff_id`, `class_subject_id` and `academic_session_id`
have **no path to change** once created - not merely unvalidated, structurally absent from the
request. The assignment a row names is fixed for its lifetime; reassigning the responsibility
to someone else is `POST .../end` the current row, then a fresh `POST` for the new one - never
an edit to the existing row.

### 1.3 The status never moves through `PUT`

`end` and `cancel` are the **only** doors to the status, each gated on its own permission,
following Module 06's `withdraw`/`cancel` pattern.

---

## 2. The assignment resource

```json
{
  "data": {
    "id": 12,
    "teaching_staff": { "id": 4, "name": "Amina Yusuf", "staff_type": "TEACHING", "...": "..." },
    "class_subject": { "id": 5, "school_class": { "...": "..." }, "subject": { "...": "..." }, "status": "ACTIVE" },
    "academic_session": { "id": 3, "name": "2026/2027", "status": "ACTIVE", "...": "..." },
    "status": "ACTIVE",
    "notes": null,
    "ended_at": null,
    "created_at": "2026-10-02T09:00:00+00:00",
    "updated_at": "2026-10-02T09:00:00+00:00"
  },
  "message": "..."
}
```

Every related record is rendered through its own module's resource - `StaffResource`,
`ClassSubjectResource` (itself nesting `SchoolClassResource`/`SubjectResource`),
`AcademicSessionResource` - so this module maintains no second field list for any of them, and
`StaffResource`'s own privacy rules (no password, no token, no permissions) apply here
unchanged.

---

## 3. Endpoints

| Method | Path | Permission |
|---|---|---|
| `GET` | `/teacher-assignments` | `teacher_assignments.view` |
| `POST` | `/teacher-assignments` | `teacher_assignments.create` |
| `GET` | `/teacher-assignments/{id}` | `teacher_assignments.view` |
| `PUT` | `/teacher-assignments/{id}` | `teacher_assignments.update` |
| `POST` | `/teacher-assignments/{id}/end` | `teacher_assignments.end` |
| `POST` | `/teacher-assignments/{id}/cancel` | `teacher_assignments.cancel` |

All six require an active bearer token (`auth:api` + `active`).

**There is no `GET /staff/{id}/assignments` or `GET /class-subjects/{id}/teachers`.** The
canonical list's `teaching_staff_id` and `class_subject_id` filters already answer both
questions; building dedicated routes for them now would be exactly the speculative convenience
endpoint the brief cautions against.

### 3.1 `GET /teacher-assignments`

| Parameter | Values | Notes |
|---|---|---|
| `teaching_staff_id` | integer | Must exist - "what does this teacher teach" |
| `class_subject_id` | integer | Must exist - "who teaches this class subject" |
| `academic_session_id` | integer | Must exist - "what was assigned this session" |
| `status` | `ACTIVE`, `ENDED`, `CANCELLED` | |
| `per_page` | 1–100, default 15 | |

**There is no `search`** - an assignment holds no text field of its own; sending
`?search=...` is rejected with `422`, not silently ignored.

### 3.2 `POST /teacher-assignments`

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"teaching_staff_id":4,"class_subject_id":5,"academic_session_id":3}' \
  http://localhost:8000/api/v1/teacher-assignments
```

**201.** Status is always `ACTIVE`.

**Every reference is checked against the database, never trusted from the client:**

- `teaching_staff_id` must exist, be `staff_type: TEACHING`, and be actively employed
  (`EmploymentStatus::ACTIVE`) - a single combined check. The staff member's **login** status
  is deliberately not checked: employment eligibility and login capability are separate facts
  in this project (`StaffService::deactivate()` leaves a login untouched on purpose), and a
  suspended account does not stop someone being a teacher of record.
- `class_subject_id` must exist and be `ACTIVE`, **and its class must be `ACTIVE`, and that
  class's class level must also be `ACTIVE`** - a class subject can remain nominally `ACTIVE`
  after its own class is archived (Module 07's own design keeps the two statuses independent),
  so all three are checked independently, the identical multi-level guard Module 06 and Module
  07 both apply to their own class-hierarchy references.
- `academic_session_id` must exist and must not be `COMPLETED`.
- **At most one active assignment per class subject per session.** A second `POST` for the
  same `(class_subject_id, academic_session_id)` pair - even naming a different teacher - is
  refused with `422`, naming `class_subject_id`:

  > This class subject already has an active teacher for this academic session. End that
  > assignment first.

  Enforced twice: a scoped `Rule::unique` at validation, and a database unique index
  (`class_subject_id`, `academic_session_id`, `active_marker`) as the race-safe backstop for
  two requests that pass validation in the same instant.

### 3.3 `PUT /teacher-assignments/{id}`

The **only** accepted field is `notes`. Refused with `422` on a terminal (`ENDED`/`CANCELLED`)
assignment - the record is closed.

### 3.4 `POST /teacher-assignments/{id}/end` and `POST /teacher-assignments/{id}/cancel`

Both take one optional field:

```json
{ "notes": "Transferred to another school mid-term." }
```

Only valid from `ACTIVE`. Neither is reversible.

---

## 4. Lifecycle and reassignment

```
ACTIVE → ENDED
       → CANCELLED
```

| Status | Meaning | Reversible |
|---|---|---|
| `ACTIVE` | The current teacher of record for this class subject and session | — |
| `ENDED` | The teacher stopped teaching this class subject this session - left, reassigned, replaced mid-year | **No** |
| `CANCELLED` | The row should not have existed - the delete-replacement | **No** |

The identical three-state shape `EnrollmentStatus` uses, for the identical reason: `ENDED` and
`CANCELLED` are different facts, and collapsing them would lose "how many assignments genuinely
concluded versus were data-entry mistakes".

### 4.1 Reassignment is two calls, not one

There is no `/reassign` endpoint. The scenario -

```
Teacher A → Mathematics → SS 1  (this year)
Teacher B → Mathematics → SS 1  (later, same year or the next)
```

- is handled by composing the two primitives this module already has:

1. `POST /teacher-assignments/{teacherA'sAssignmentId}/end`
2. `POST /teacher-assignments` naming Teacher B, the same `class_subject_id` and the same
   `academic_session_id`

Ending the first assignment clears its `active_marker`, which is precisely what frees that
`(class_subject_id, academic_session_id)` pair for the second call to claim. **Teacher A's row
is never deleted or repointed** - it remains readable forever with `status: ENDED` and the
correct historical `teaching_staff_id` - which is what lets a future report answer "who taught
this before Teacher B" without any extra bookkeeping.

---

## 5. Permissions

| Permission | Grants |
|---|---|
| `teacher_assignments.view` | `GET /teacher-assignments`, `GET /teacher-assignments/{id}` |
| `teacher_assignments.create` | `POST /teacher-assignments` |
| `teacher_assignments.update` | `PUT /teacher-assignments/{id}` |
| `teacher_assignments.end` | `POST /teacher-assignments/{id}/end` |
| `teacher_assignments.cancel` | `POST /teacher-assignments/{id}/cancel` |

**No `teacher_assignments.delete`** - no delete endpoint is registered.

### 5.1 Role grants

| Role | `view` | `create` | `update` | `end` | `cancel` |
|---|:--:|:--:|:--:|:--:|:--:|
| `SUPER_ADMIN` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `ADMIN` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `REGISTRAR` | ✓ | — | — | — | — |
| `STAFF` | — | — | — | — | — |
| `STUDENT` | — | — | — | — | — |

**`REGISTRAR` holds `view` only** - a deliberate departure from Modules 05-07's pattern of
granting `REGISTRAR` full CRUD. `RoleSeeder`'s own description of the role names *"admissions,
enrollment and student records"* - staffing assignment is not among them, unlike those three.
Deciding who teaches what is closer to the supervisory staffing decision Module 03 already
withholds from `REGISTRAR` (`staff.activate`/`staff.deactivate`) than to admitting or placing a
student. A registrar still needs to **read** assignments - to answer "who teaches this class"
when building a timetable or a report - so `view` is granted.

**`STAFF` holds nothing**, regardless of whether they themselves are the teacher named on a
row. Seeing their own teaching load is a future self-service question, not a licence to manage
the whole school's assignments.

---

## 6. Client checklist

- [ ] Send `Accept: application/json` and a bearer token on every call.
- [ ] Always send all three references on create - `teaching_staff_id`, `class_subject_id`,
      `academic_session_id`.
- [ ] Expect `422` naming `class_subject_id` if that class subject already has an active
      teacher for that session - **even when you named a different teacher**.
- [ ] To reassign, `end` the old assignment first, then `POST` a new one. There is no
      `/reassign` endpoint.
- [ ] Do not send `status` anywhere. Use `end` or `cancel`.
- [ ] Do not try to `DELETE` or `PATCH` an assignment. Both are `405`.
- [ ] `PUT` only ever changes `notes` - nothing else has a key to send.
- [ ] Do not send `search` to `GET /teacher-assignments` - filter by `teaching_staff_id`,
      `class_subject_id`, `academic_session_id` or `status` instead.
- [ ] Do not expect a suspended staff login to block a new assignment, or an existing one to
      end automatically - employment eligibility and login status are independent facts.
