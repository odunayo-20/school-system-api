# Admission Management API

The school's decision record for each applicant: who applied, for which intake, and what was
decided.

Module 05. Seven endpoints, one table, and a boundary that matters more than the endpoints do:
**Admission ≠ Student ≠ Enrollment.**

- [0. Read this first: an admission is not a pupil](#0-read-this-first-an-admission-is-not-a-pupil)
- [1. Conventions](#1-conventions)
- [2. The admission resource](#2-the-admission-resource)
- [3. Endpoints](#3-endpoints)
- [4. The admission lifecycle](#4-the-admission-lifecycle)
- [5. Permissions](#5-permissions)
- [6. Client checklist](#6-client-checklist)

---

## 0. Read this first: an admission is not a pupil

```
Admission → Student → (future) Enrollment
```

An **admission** is the school's process of deciding whether to admit somebody. A **student**
is that person's persistent identity once admitted. An **enrollment** (a future module) is
their academic placement for a specific session. These are three different facts, kept in
three different tables on purpose.

`POST /admissions` creates **only** an admission row: a snapshot of who applied and which
intake they are applying for. No `Student` exists yet. A student is created **only** when
`POST /admissions/{id}/admit` succeeds, and it is created by calling the *same*
`StudentService` Module 04 already owns - this module does not re-implement pupil creation.

`entry_class_level_id` records what the applicant is **seeking**, not where they have been
placed. It is never copied onto the student that gets created, and it never becomes a class or
section assignment. That is a future enrollment module's job.

---

## 1. Conventions

Inherited from the [API reference](README.md): bearer tokens, `Accept: application/json`, the
`{ "data": ..., "message": ... }` envelope, `meta`/`links` as siblings of `data`, and
200/201/401/403/404/405/422/429.

### 1.1 There is no delete endpoint

`DELETE /admissions/{id}` is not registered. **405** with `Allow: GET, HEAD, PUT`.

An admission is a historical business record of a decision the school made. It does not stop
existing because the decision is old - a rejected or withdrawn application is worth keeping so
"how many of this year's applicants did we admit?" still has an answer next year.

### 1.2 `PUT` only, not `PATCH`, and it is a whole-record write

`PATCH` gets **405**. `first_name` and `academic_session_id` are **required** on `PUT`, so a
client cannot half-update the record.

### 1.3 The status never moves through `PUT`

`UpdateAdmissionRequest` has **no `status` key at all** - not "ignored", genuinely absent, the
same protection `UpdateStudentRequest` gives `user_id`. Admitting an applicant creates a
`Student` inside a transaction; a `PUT` that could also reach that transition would let a
client trigger it by amending a field instead of calling the dedicated endpoint, and would make
it impossible to grant "may edit a pending application" separately from "may decide it".

`admit`, `reject` and `withdraw` are the **only** doors to the status, each gated on its own
permission.

---

## 2. The admission resource

```json
{
  "data": {
    "id": 12,
    "admission_number": "ADM-0012",
    "first_name": "Ada",
    "middle_name": "Ngozi",
    "last_name": "Okonkwo",
    "full_name": "Ada Ngozi Okonkwo",
    "date_of_birth": "2015-04-02",
    "gender": "FEMALE",
    "status": "PENDING",
    "notes": null,
    "decided_at": null,
    "academic_session": { "id": 3, "name": "2026/2027", "status": "UPCOMING", "...": "..." },
    "entry_class_level": { "id": 1, "name": "Primary 1", "code": "PRI1", "...": "..." },
    "student": null,
    "created_at": "2026-09-29T09:14:00+00:00",
    "updated_at": "2026-09-29T09:14:00+00:00"
  },
  "message": "..."
}
```

`academic_session` and `entry_class_level` are rendered through their own module's resource, so
this module does not maintain a second, divergent field list for either.

`student` is `null` until the admission is `ADMITTED`, and is rendered through
`StudentResource` - the same privacy rules Module 04 already settled on (no email, no account
internals) apply here without being re-decided.

### 2.1 `admission_number`

Optional on create, exactly like `student_number`: a school with its own scheme supplies one;
otherwise it is derived from the record's own primary key (`ADM-0012`), using the identical
UUID-reservation-inside-a-transaction technique `StudentService` uses, for the identical
reason - `count() + 1` is wrong under concurrency, and a fixed placeholder collides on the
unique index for the second of two simultaneous creates.

**This is a different number from `student_number`.** An admission identifies one *attempt*; a
person can have more than one (apply, be refused, apply again). The number this admission
creates for the resulting pupil - if it is ever admitted - is `StudentService`'s to derive, not
copied from here.

### 2.2 What is deliberately absent

No `class_id`, `section_id`, or any field that would make this the authoritative placement.
`entry_class_level` answers "what are they applying for", never "where are they now".

No credential fields (`email`, `password`, `role`) - an admission never creates a login, and
neither does the `Student` it eventually creates (Module 04's rule, unchanged).

---

## 3. Endpoints

| Method | Path | Permission |
|---|---|---|
| `GET` | `/admissions` | `admissions.view` |
| `POST` | `/admissions` | `admissions.create` |
| `GET` | `/admissions/{id}` | `admissions.view` |
| `PUT` | `/admissions/{id}` | `admissions.update` |
| `POST` | `/admissions/{id}/admit` | `admissions.admit` |
| `POST` | `/admissions/{id}/reject` | `admissions.reject` |
| `POST` | `/admissions/{id}/withdraw` | `admissions.withdraw` |

All seven require an active bearer token (`auth:api` + `active`).

### 3.1 `GET /admissions`

| Parameter | Values | Notes |
|---|---|---|
| `search` | free text | Matches admission number and all three snapshot name parts |
| `status` | `PENDING`, `ADMITTED`, `REJECTED`, `WITHDRAWN` | |
| `academic_session_id` | integer | Must reference an existing session |
| `entry_class_level_id` | integer | Must reference an existing class level |
| `per_page` | 1–100, default 15 | |

**Ordering: newest first.** Unlike the student roll (read alphabetically, the way a register is
read), an admissions list is a queue of decisions to work through - the question it answers is
"what came in lately", not "who is this alphabetically".

### 3.2 `POST /admissions`

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"first_name":"Ada","last_name":"Okonkwo","academic_session_id":3}' \
  http://localhost:8000/api/v1/admissions
```

**201.** Status is always `PENDING`.

| Field | Required | Rules |
|---|---|---|
| `first_name` | **yes** | string, max 100 |
| `middle_name` | no | string, max 100 |
| `last_name` | no | string, max 100 |
| `date_of_birth` | no | date, not in the future |
| `gender` | no | `MALE` \| `FEMALE` |
| `academic_session_id` | **yes** | must exist and not be `COMPLETED` |
| `entry_class_level_id` | no | must exist and be `ACTIVE` (selectable) |
| `admission_number` | no | max 50, unique |
| `notes` | no | max 1000 |

**Not accepted, and not merely ignored:** `status`, `student_id`, `email`, `password`, `role`,
`class_id`, `section_id`.

### 3.3 `GET /admissions/{id}` and `PUT /admissions/{id}`

Standard read and whole-record amend. A **decided** admission (anything but `PENDING`) refuses
every amend with **422** - see §4.2.

### 3.4 `POST /admissions/{id}/admit`

Accepts the applicant. **Creates and links a `Student`** in the same transaction. Only valid
from `PENDING`.

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" http://localhost:8000/api/v1/admissions/12/admit
```

200, with the created student nested under `data.student`.

### 3.5 `POST /admissions/{id}/reject` and `POST /admissions/{id}/withdraw`

Both take one optional field:

```json
{ "notes": "Did not meet the entry requirement." }
```

Neither creates or touches a `Student`. Only valid from `PENDING`.

---

## 4. The admission lifecycle

```
PENDING → ADMITTED
        → REJECTED
        → WITHDRAWN
```

| Status | Meaning | Reversible |
|---|---|---|
| `PENDING` | Recorded, no decision yet | — |
| `ADMITTED` | Accepted; a `Student` was created and linked | **No** |
| `REJECTED` | Declined by the school | **No** |
| `WITHDRAWN` | Withdrawn by the applicant before a decision | **No** |

There is deliberately **no `UNDER_REVIEW` stage**. Nothing in this project names a reviewer
distinct from the decision-maker, and `RoleSeeder`'s own words give `REGISTRAR` and `ADMIN` the
whole of "handling admissions" rather than splitting intake from decision across two roles. A
stage with nobody assigned to it and no rule depending on it is not a stage worth having.

### 4.1 All three outcomes are terminal, and none can be repeated

Unlike Module 03's `staff.activate`/`staff.deactivate` - a reversible toggle, where repeating a
transition on a record already in that state is treated as an idempotent success - an admission
decision is a **one-shot event with a side effect**. Admitting the same admission twice, or
rejecting one that was already admitted, is refused with **422**:

> This admission has already been decided (ADMITTED), so it cannot be rejected.

This is what makes "admit an admission twice" and "reject an already-admitted admission" both
impossible: the very first successful transition moves the status out of `PENDING`, and every
later call - whatever verb it uses - fails the same precondition before it can do anything.

### 4.2 A decided admission is read only in full

Exactly like Module 04's departed-pupil rule: any amend to a non-`PENDING` admission is refused
with **422**, not merely the status:

> This admission has already been decided, so the record can no longer be amended.

The decision is a fact about a moment. A later amend must not be able to rewrite what the
applicant looked like when the decision was made.

---

## 5. Permissions

| Permission | Grants |
|---|---|
| `admissions.view` | `GET /admissions`, `GET /admissions/{id}` |
| `admissions.create` | `POST /admissions` |
| `admissions.update` | `PUT /admissions/{id}` |
| `admissions.admit` | `POST /admissions/{id}/admit` |
| `admissions.reject` | `POST /admissions/{id}/reject` |
| `admissions.withdraw` | `POST /admissions/{id}/withdraw` |

**No `admissions.delete`.** No delete endpoint is registered, so a permission for it would be a
grant with no meaning.

**Three separate transition permissions**, following Module 03's `activate`/`deactivate` split:
each is a state change with a side effect beyond the record it names, so each can be granted
independently of `admissions.update`.

### 5.1 Role grants

| Role | `view` | `create` | `update` | `admit` | `reject` | `withdraw` |
|---|:--:|:--:|:--:|:--:|:--:|:--:|
| `SUPER_ADMIN` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `ADMIN` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `REGISTRAR` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `STAFF` | — | — | — | — | — | — |
| `STUDENT` | — | — | — | — | — | — |

**`REGISTRAR` holds all six**, unlike Module 03's split where `REGISTRAR` gets `staff.create`/
`staff.update` but *not* `staff.activate`/`staff.deactivate` (ending someone's employment is a
supervisory decision distinct from a registrar's ordinary duties). Admission decisions are not
analogous: `RoleSeeder`'s own description of the role is *"Handles admissions, enrollment and
student records"*. A registrar who could record an application but not decide it would be
unable to do the job the role exists for.

**`STAFF` and `STUDENT` hold nothing.** A teacher's need to know a pupil's class is an
enrollment question for a future module; a pupil's own account keeps only Module 01's
`profile.*` pair.

---

## 6. Client checklist

- [ ] Send `Accept: application/json` and a bearer token on every call.
- [ ] Always send `academic_session_id` on create and on `PUT` - it is required, unlike on the
      student roll, because an admission is meaningless without the intake it targets.
- [ ] Do not send `status` anywhere. Use `admit`, `reject` or `withdraw`.
- [ ] Do not expect `POST /admissions` to create a `Student`. It does not, until `admit`.
- [ ] Expect `data.student` to be `null` until the admission is `ADMITTED`.
- [ ] Send `first_name` on every `PUT`; it is a whole-record write.
- [ ] Do not try to `DELETE` or `PATCH` an admission. Both are `405`.
- [ ] Expect `422` (not `409`) when deciding an already-decided admission, or amending one.
- [ ] Do not look for a class or section on an admission or on the student it creates. Neither
      is there, by design.
