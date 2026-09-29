# Student Enrollment API

The authoritative academic placement: which student sits in which class and section, for one
academic session.

Module 06. Six endpoints, one table, and a boundary that matters more than the endpoints do:
**Enrollment is the only source of truth for a student's placement — `students` has none.**

- [0. Read this first: Enrollment, not Student, is the placement](#0-read-this-first-enrollment-not-student-is-the-placement)
- [1. Conventions](#1-conventions)
- [2. The enrollment resource](#2-the-enrollment-resource)
- [3. Endpoints](#3-endpoints)
- [4. The enrollment lifecycle](#4-the-enrollment-lifecycle)
- [5. Permissions](#5-permissions)
- [6. Client checklist](#6-client-checklist)

---

## 0. Read this first: Enrollment, not Student, is the placement

```
Student → Admission → Enrollment → (future) Results / Promotion
```

A student accumulates **one enrollment row per academic session** they are placed in:

```
2024/2025 → JSS 2 → A
2025/2026 → JSS 3 → A
2026/2027 → SS 1  → B
```

`students` has no `current_class_id`, `current_section_id` or `current_session_id` - it never
will. Asking "what class is this student in?" means reading their enrollments, not their
student record. Each row is written once and **never repointed** at a different student,
session, class or section - a future Promotion module creates a **new** row for a new session;
it never mutates an old one.

**Admission is not a prerequisite.** A student created directly through `POST /students`
(Module 04, no admission at all) and a student created by admitting an application (Module 05)
are equally eligible for enrollment. The only eligibility question this module asks is whether
the student is currently `ACTIVE` - see §4.

---

## 1. Conventions

Inherited from the [API reference](README.md): bearer tokens, `Accept: application/json`, the
`{ "data": ..., "message": ... }` envelope, `meta`/`links` as siblings of `data`, and
200/201/401/403/404/405/422/429.

### 1.1 There is no delete endpoint

`DELETE /enrollments/{id}` is not registered. **405** with `Allow: GET, HEAD, PUT`.

An enrollment is academic history that a future results, attendance, promotion or report-card
module will reference. `POST /enrollments/{id}/cancel` is the record-preserving replacement: a
mistaken enrollment is voided in place, never erased.

### 1.2 `PUT` only, not `PATCH`, and it touches almost nothing

`PATCH` gets **405**. `PUT` amends **only** `enrollment_date` and `notes`. There is no key,
anywhere, for `student_id`, `academic_session_id`, `school_class_id`, `section_id` or `status` -
not "ignored", genuinely absent from the request. The placement a student's enrollment records
is authoritative academic history the moment it is created; letting a `PUT` repoint it would
let "fix a typo" and "move a student to a different class" collide in one endpoint with one
permission. A transfer/class-change operation, if this project ever needs one, is a deliberate
future domain operation - not a field on this endpoint.

### 1.3 The status never moves through `PUT`

`withdraw` and `cancel` are the **only** doors to the status, each gated on its own permission,
following Module 05's `admit`/`reject`/`withdraw` pattern.

---

## 2. The enrollment resource

```json
{
  "data": {
    "id": 12,
    "student": { "id": 7, "student_number": "STU-0007", "full_name": "Ada Okonkwo", "...": "..." },
    "academic_session": { "id": 3, "name": "2026/2027", "status": "ACTIVE", "...": "..." },
    "school_class": { "id": 5, "name": "JSS 1", "code": "JSS1", "...": "..." },
    "section": { "id": 9, "name": "A", "school_class_id": 5, "...": "..." },
    "enrollment_date": "2026-09-05",
    "status": "ACTIVE",
    "notes": null,
    "status_changed_at": null,
    "created_at": "2026-09-29T09:14:00+00:00",
    "updated_at": "2026-09-29T09:14:00+00:00"
  },
  "message": "..."
}
```

Every related record is rendered through its **own** module's resource - `StudentResource`,
`AcademicSessionResource`, `SchoolClassResource`, `SectionResource` - so this module maintains
no second, divergent field list for any of them, and `StudentResource`'s privacy rules (no
email, no account internals) apply here unchanged.

### 2.1 What is deliberately absent

No enrollment reference number. The natural key `(student, session)` is already unique and
directly filterable; nothing needs a second identifier to find one.

No `search` filter - see §3.1.

No `class_level_id` on the enrollment itself - it is reachable through `school_class.class_level`
if a client needs it, and duplicating it here would be a second, divergent copy of a fact
`classes` already owns.

---

## 3. Endpoints

| Method | Path | Permission |
|---|---|---|
| `GET` | `/enrollments` | `enrollments.view` |
| `POST` | `/enrollments` | `enrollments.create` |
| `GET` | `/enrollments/{id}` | `enrollments.view` |
| `PUT` | `/enrollments/{id}` | `enrollments.update` |
| `POST` | `/enrollments/{id}/withdraw` | `enrollments.withdraw` |
| `POST` | `/enrollments/{id}/cancel` | `enrollments.cancel` |

All six require an active bearer token (`auth:api` + `active`).

### 3.1 `GET /enrollments`

| Parameter | Values | Notes |
|---|---|---|
| `student_id` | integer | Must exist |
| `academic_session_id` | integer | Must exist |
| `school_class_id` | integer | Must exist |
| `section_id` | integer | Must exist |
| `status` | `ACTIVE`, `WITHDRAWN`, `CANCELLED` | |
| `per_page` | 1–100, default 15 | |

**There is no `search`.** Unlike the student roll or the admissions queue, an enrollment holds
no text field of its own. Sending `?search=...` is **rejected with 422**, not silently ignored -
the four FK filters already answer every realistic question this list needs to: a student's
placement history (`student_id`), a session's intake (`academic_session_id`), a class's roster
(`school_class_id`, optionally narrowed further by `section_id`).

**Ordering: newest first**, the same convention as the admissions queue.

### 3.2 `POST /enrollments`

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"student_id":7,"academic_session_id":3,"school_class_id":5,"section_id":9,"enrollment_date":"2026-09-05"}' \
  http://localhost:8000/api/v1/enrollments
```

**201.** Status is always `ACTIVE`.

| Field | Required | Rules |
|---|---|---|
| `student_id` | **yes** | must exist and the student must be `ACTIVE` |
| `academic_session_id` | **yes** | must exist and not be `COMPLETED` |
| `school_class_id` | **yes** | must exist, be `ACTIVE`, and its class level must be `ACTIVE` |
| `section_id` | **yes** | must exist, be `ACTIVE`, and belong to `school_class_id` |
| `enrollment_date` | **yes** | must fall within the named session's own start/end dates |
| `notes` | no | max 1000 |

**Not accepted, and not merely ignored:** `status`.

**Every reference is checked against the database, never trusted from the client:**

- A section from a **different** class than the one named is refused - `422` on `section_id` -
  even though both IDs individually exist.
- A class whose own status is `ACTIVE` but whose **class level** has been retired is still
  refused - the class-level check needs a loaded relation, so it runs in the service after the
  form request's own checks pass.
- **One student, one enrollment per session.** A second `POST` for the same
  `(student_id, academic_session_id)` pair - even into a different class - is refused with
  `422`, naming `student_id`. This is enforced twice: a scoped `Rule::unique` at validation
  (the normal path) and a `unique(student_id, academic_session_id)` database index (the
  race-safe backstop for two requests that both pass validation in the same instant - the
  second is still refused with the same `422`, never a raw `500`).

### 3.3 `GET /enrollments/{id}` and `PUT /enrollments/{id}`

Standard read and amend. `PUT` requires `enrollment_date`; `notes` is optional. A **terminal**
enrollment (`WITHDRAWN` or `CANCELLED`) refuses every amend with **422** - see §4.2.

### 3.4 `POST /enrollments/{id}/withdraw` and `POST /enrollments/{id}/cancel`

Both take one optional field:

```json
{ "notes": "Transferred to another school mid-term." }
```

Only valid from `ACTIVE`. Neither is reversible.

---

## 4. The enrollment lifecycle

```
ACTIVE → WITHDRAWN
       → CANCELLED
```

| Status | Meaning | Reversible |
|---|---|---|
| `ACTIVE` | The operative placement for this session | — |
| `WITHDRAWN` | The student left this placement before the session ended | **No** |
| `CANCELLED` | The row should not have existed - the delete-replacement | **No** |

There is **no `COMPLETED` status**. Nothing in this module observes "the session ended" as an
event - adding one would need something to set it, and this project has no scheduled job or
hook to do that. A past enrollment stays legible as history through the **academic session's
own** status (`COMPLETED` there already means "this year has run"), not a copy of that fact on
every enrollment row it produced. `ACTIVE` simply means "never withdrawn or cancelled",
whether the session it belongs to is current or three years gone.

### 4.1 Both outcomes are terminal, and neither can be repeated

Repeating a transition, or attempting the other one, on an already-ended enrollment is refused
with **422**:

> This enrollment has already ended (WITHDRAWN), so it cannot be cancelled.

This mirrors Module 05's admission-decision posture, not Module 03's reversible
activate/deactivate toggle: ending a placement is a one-shot fact, not a switch to flip back.

### 4.2 A terminal enrollment is read only in full

Any amend to a non-`ACTIVE` enrollment is refused with **422**, not merely the status:

> This enrollment has ended, so the record can no longer be amended.

### 4.3 A known, accepted limitation: a cancelled session slot stays claimed

The unique `(student_id, academic_session_id)` rule has no opinion about status - a
`CANCELLED` enrollment still occupies that pair. Re-enrolling the same student into the same
session after cancelling a mistaken row is **refused** today; the correction has to happen
before cancelling, or by using a different session. See the Module 06 audit for why this is
documented rather than silently widened.

---

## 5. Permissions

| Permission | Grants |
|---|---|
| `enrollments.view` | `GET /enrollments`, `GET /enrollments/{id}` |
| `enrollments.create` | `POST /enrollments` |
| `enrollments.update` | `PUT /enrollments/{id}` |
| `enrollments.withdraw` | `POST /enrollments/{id}/withdraw` |
| `enrollments.cancel` | `POST /enrollments/{id}/cancel` |

**No `enrollments.delete`** - no delete endpoint is registered.

### 5.1 Role grants

| Role | `view` | `create` | `update` | `withdraw` | `cancel` |
|---|:--:|:--:|:--:|:--:|:--:|
| `SUPER_ADMIN` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `ADMIN` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `REGISTRAR` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `STAFF` | — | — | — | — | — |
| `STUDENT` | — | — | — | — | — |

**`REGISTRAR` holds all five** - `RoleSeeder`'s own words name "enrollment" explicitly among
the registrar's duties.

**`STAFF` holds nothing**, deliberately. A teacher's need to know which class a pupil is in is
real, but a broad `enrollments.view` would let them browse every student's placement in the
school, not only their own class. A scoped "my class roster" answer is a future module's job
(Results/Attendance), not a reason to widen this grant now.

**`STUDENT` holds nothing.** Module 01's `profile.*` pair stays the only self-service surface.

---

## 6. Client checklist

- [ ] Send `Accept: application/json` and a bearer token on every call.
- [ ] Never look for a class/section/session on a `Student` record - it is not there. Read the
      student's enrollments instead.
- [ ] Always send all four references on create - `student_id`, `academic_session_id`,
      `school_class_id`, `section_id` - plus `enrollment_date`.
- [ ] Expect `422` naming `student_id` if the student already has an enrollment for that
      session, even into a different class.
- [ ] Do not send `status` anywhere. Use `withdraw` or `cancel`.
- [ ] Do not try to `DELETE` or `PATCH` an enrollment. Both are `405`.
- [ ] `PUT` only ever changes `enrollment_date` and `notes` - nothing else has a key to send.
- [ ] Expect `422` when amending a `WITHDRAWN` or `CANCELLED` enrollment.
- [ ] Do not assume `ACTIVE` means "the current session" - it means "never ended", and applies
      equally to a placement from three years ago.
