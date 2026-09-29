# Subject & Subject Catalogue Management API

The reusable subject catalogue, and the offering of its subjects to classes.

Module 07. Nine endpoints across two resources, and a boundary that matters more than the
endpoints do: **a Subject is not a Class Subject.**

- [0. Read this first: catalogue vs offering](#0-read-this-first-catalogue-vs-offering)
- [1. Conventions](#1-conventions)
- [2. The subject resource](#2-the-subject-resource)
- [3. The class subject resource](#3-the-class-subject-resource)
- [4. Endpoints](#4-endpoints)
- [5. Lifecycle](#5-lifecycle)
- [6. Permissions](#6-permissions)
- [7. Client checklist](#7-client-checklist)

---

## 0. Read this first: catalogue vs offering

```
Subject (Mathematics)  →  Class Subject (JSS 2 offers Mathematics)  →  (future) Subject Teacher
```

A **Subject** is a reusable catalogue entry: "Mathematics" exists once, school-wide, regardless
of how many classes teach it. A **Class Subject** is one class's offering of a subject: "JSS 2
teaches Mathematics" is a separate row from "JSS 3 teaches Mathematics", even though both point
at the same `Subject`.

Neither table holds a `teacher_id`, `staff_id`, `assessment_id` or `score` column. Assigning a
teacher to a class subject is a future module's job, built on `Staff`/`StaffType` - **not** a
new `Teacher` entity - and it will reference a `class_subjects.id`, never a bare `subjects.id`,
so a teacher is always assigned to "Mathematics as taught in JSS 2", not to "Mathematics" in
the abstract.

Offerings attach to **classes** (`school_class_id`), not class levels. JSS 2 and JSS 3 both sit
inside the Junior Secondary level but do not offer identical subjects, so a level-scoped
offering would be too coarse to be true - the same granularity Module 06 already chose for
enrollments.

---

## 1. Conventions

Inherited from the [API reference](README.md): bearer tokens, `Accept: application/json`, the
`{ "data": ..., "message": ... }` envelope, `meta`/`links` as siblings of `data`, and
200/201/401/403/404/405/422/429.

### 1.1 `Subject` supports `DELETE`; `ClassSubject` does not

A subject is structurally a catalogue entry - the same family as `ClassLevel`, `SchoolClass`
and `Section`, all of which support a guarded `DELETE` - not a person or a one-shot decision
like an admission or an enrollment. `DELETE /subjects/{id}` is refused with **422** while any
class still offers it (active or not), the identical convention
`AcademicStructureService::deleteClassLevel()` already uses.

`DELETE /class-subjects/{id}` is **not registered** - **405** with `Allow: GET, HEAD, PUT`. A
class subject is the anchor a future teacher assignment and a future assessment will
reference, the same posture Module 06 takes toward enrollments. Removing a subject from a
class is `status: INACTIVE` through the ordinary `PUT`, not a delete.

### 1.2 `PUT /class-subjects/{id}` accepts only `status`

Unlike every Store/Update pair before it, a class subject's pairing (`school_class_id`,
`subject_id`) has **no path to change** once created - not merely unvalidated, structurally
absent from the request. Changing which subject a row means partway through its life would let
a future assessment silently start meaning something else.

Unlike `Admission`/`Enrollment`, this status change is reachable through the **ordinary**
`PUT`, not a dedicated workflow endpoint: it is a freely reversible toggle ("is this currently
offered"), matching `CatalogStatus`'s own semantics on class levels, classes and sections - not
a one-shot decision with a side effect.

---

## 2. The subject resource

```json
{
  "data": {
    "id": 1,
    "name": "Mathematics",
    "code": "MAT",
    "sort_order": 1,
    "status": "ACTIVE",
    "class_subjects_count": 3,
    "created_at": "2026-10-01T09:00:00+00:00",
    "updated_at": "2026-10-01T09:00:00+00:00"
  },
  "message": "..."
}
```

`class_subjects_count` is present only on `GET /subjects/{id}` (`whenCounted`), matching
`ClassLevelResource`'s identical `classes_count` convention.

### 2.1 `code`

Required, unique school-wide, normalised to trimmed upper-case on the way in - the identical
rule `class_levels.code` and `classes.code` already use. `"mat"`, `"MAT"` and `"  mat  "` are
one code; the second attempt to create any of them is refused with **422**, never a raw
database error.

---

## 3. The class subject resource

```json
{
  "data": {
    "id": 5,
    "school_class": { "id": 1, "name": "JSS 2", "code": "JSS2", "...": "..." },
    "subject": { "id": 1, "name": "Mathematics", "code": "MAT", "...": "..." },
    "status": "ACTIVE",
    "created_at": "2026-10-01T09:05:00+00:00",
    "updated_at": "2026-10-01T09:05:00+00:00"
  },
  "message": "..."
}
```

`school_class` and `subject` are rendered through their own module's resource
(`SchoolClassResource`, `SubjectResource`), so this module maintains no second field list for
either.

---

## 4. Endpoints

| Method | Path | Permission |
|---|---|---|
| `GET` | `/subjects` | `subjects.view` |
| `POST` | `/subjects` | `subjects.create` |
| `GET` | `/subjects/{id}` | `subjects.view` |
| `PUT` | `/subjects/{id}` | `subjects.update` |
| `DELETE` | `/subjects/{id}` | `subjects.delete` |
| `GET` | `/class-subjects` | `class_subjects.view` |
| `POST` | `/class-subjects` | `class_subjects.create` |
| `GET` | `/class-subjects/{id}` | `class_subjects.view` |
| `PUT` | `/class-subjects/{id}` | `class_subjects.update` |

All nine require an active bearer token (`auth:api` + `active`).

### 4.1 `GET /subjects`

| Parameter | Values | Notes |
|---|---|---|
| `search` | free text | Matches the subject name (inherited from the base list request) |
| `code` | text | Exact match, case-insensitive |
| `status` | `ACTIVE`, `INACTIVE`, `ARCHIVED` | |
| `active_only` | `true`/`false`/`1`/`0` | Backs a "choose a subject" picker |
| `per_page` | 1–100, default 15 | |

Ordered by `sort_order`, then `name` - the identical convention `ClassLevel` uses.

### 4.2 `POST /subjects`

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"name":"Mathematics","code":"MAT"}' \
  http://localhost:8000/api/v1/subjects
```

**201.** `name` and `code` are required, both unique; `sort_order` and `status` are optional
(`status` defaults to `ACTIVE`).

### 4.3 `PUT /subjects/{id}`

Whole-record write: `name` and `code` are **required**, exactly as `ClassLevel`'s own amend.
`status` is amendable here too - a freely reversible toggle, not a workflow transition.

### 4.4 `DELETE /subjects/{id}`

Refused with **422** while any `class_subjects` row references it, whatever that row's own
status:

> This subject is still offered to at least one class and cannot be deleted. Remove its class
> offerings first.

### 4.5 `GET /class-subjects`

| Parameter | Values | Notes |
|---|---|---|
| `school_class_id` | integer | Must exist - "what does this class offer" |
| `subject_id` | integer | Must exist - "which classes offer this subject" |
| `status` | `ACTIVE`, `INACTIVE`, `ARCHIVED` | |
| `per_page` | 1–100, default 15 | |

**There is no `search`.** A class subject holds no text field of its own; sending
`?search=...` is **rejected with 422**, not silently ignored.

### 4.6 `POST /class-subjects`

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"school_class_id":1,"subject_id":1}' \
  http://localhost:8000/api/v1/class-subjects
```

**201.** Status is always `ACTIVE`.

**Every reference is checked against the database, never trusted from the client:**

- `school_class_id` must exist and be `ACTIVE`, **and its class level must also be `ACTIVE`**
  - a class can remain nominally `ACTIVE` after its own class level is archived (Module 02
    permits this), so both are checked independently, the identical two-level guard Module 06
    added for enrollments.
- `subject_id` must exist and be `ACTIVE`.
- **A class should not have the same subject attached twice.** A second `POST` for the same
  `(school_class_id, subject_id)` pair is refused with `422`, naming `subject_id`. Enforced
  twice: a scoped `Rule::unique` at validation, and a `unique(school_class_id, subject_id)`
  database index as the race-safe backstop for two requests that pass validation in the same
  instant.

### 4.7 `PUT /class-subjects/{id}`

The **only** accepted field is `status`.

```bash
curl -X PUT -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"status":"INACTIVE"}' \
  http://localhost:8000/api/v1/class-subjects/5
```

---

## 5. Lifecycle

Both `Subject` and `ClassSubject` use the existing `CatalogStatus` (`ACTIVE`/`INACTIVE`/
`ARCHIVED`) - **not** a new enum, and **not** a terminal, one-way lifecycle like `Admission` or
`Enrollment`. Either status is freely reversible in both directions at any time: a subject or
an offering that is no longer taught is marked `INACTIVE` (or `ARCHIVED` for a longer-term
retirement) rather than deleted, and can be reactivated later with no special rule standing in
the way.

A subject's own status and a class's offering of it are **independent facts**. Deactivating
"Mathematics" school-wide does **not** touch any existing `class_subjects` row that names it,
and deactivating one class's offering does not touch the subject or any other class's offering
of the same subject.

A `class_subjects` row whose class is later archived stays fully readable and amendable - the
same historical-integrity principle Module 06 applies to an enrollment whose session later
completes.

---

## 6. Permissions

| Permission | Grants |
|---|---|
| `subjects.view` | `GET /subjects`, `GET /subjects/{id}` |
| `subjects.create` | `POST /subjects` |
| `subjects.update` | `PUT /subjects/{id}` |
| `subjects.delete` | `DELETE /subjects/{id}` |
| `class_subjects.view` | `GET /class-subjects`, `GET /class-subjects/{id}` |
| `class_subjects.create` | `POST /class-subjects` |
| `class_subjects.update` | `PUT /class-subjects/{id}` |

**No `class_subjects.delete`** - no delete endpoint is registered.

### 6.1 Role grants

| Role | `subjects.*` | `class_subjects.*` |
|---|---|---|
| `SUPER_ADMIN` | view, create, update, delete | view, create, update |
| `ADMIN` | view, create, update, delete | view, create, update |
| `REGISTRAR` | view, create, update | view, create, update |
| `STAFF` | — | — |
| `STUDENT` | — | — |

**`REGISTRAR` does not hold `subjects.delete`** - the identical split
`AcademicPermissionSeeder` already applies to `class_levels.delete`, `classes.delete` and
`sections.delete`: a registrar builds the structure as part of admitting and placing students,
but retiring a catalogue entry outright sits with `ADMIN`.

**`STAFF` holds nothing over either resource - deliberately, and regardless of `StaffType`.** A
teacher does not manage the catalogue merely by teaching from it, and a non-teaching staff
member fares no differently: knowing which subjects a teacher is assigned to is a future
Teacher/Class/Subject Assignment module's question, not a licence to create or amend the
catalogue itself.

---

## 7. Client checklist

- [ ] Send `Accept: application/json` and a bearer token on every call.
- [ ] Send `name` and `code` on every `PUT /subjects/{id}`; it is a whole-record write.
- [ ] Do not expect `DELETE /subjects/{id}` to succeed while any class still offers it -
      remove the offerings (or leave them `INACTIVE`, which still blocks deletion) first.
- [ ] Do not try to `DELETE` a class subject. Use `PUT` with `status: INACTIVE`.
- [ ] `PUT /class-subjects/{id}` only ever changes `status` - nothing else has a key to send.
- [ ] Expect `422` naming `subject_id` if the class already offers that subject.
- [ ] Do not send `search` to `GET /class-subjects` - filter by `school_class_id`, `subject_id`
      or `status` instead.
- [ ] Do not assume a subject's status affects its class offerings, or vice versa - they are
      independent.
