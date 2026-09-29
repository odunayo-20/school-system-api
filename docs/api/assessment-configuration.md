# Assessment Configuration API

Which assessments exist for which class subject and term, what each is worth, and what
category it belongs to - **not** the scores themselves.

Module 09. Two catalogues, one boundary that matters more than the endpoints do:
**this module configures what will be scored; it never records a score.**

- [0. Read this first: type vs assessment, and what this module deliberately does not do](#0-read-this-first-type-vs-assessment-and-what-this-module-deliberately-does-not-do)
- [1. Conventions](#1-conventions)
- [2. The resources](#2-the-resources)
- [3. Endpoints](#3-endpoints)
- [4. Lifecycle](#4-lifecycle)
- [5. Permissions](#5-permissions)
- [6. Client checklist](#6-client-checklist)

---

## 0. Read this first: type vs assessment, and what this module deliberately does not do

```
Assessment Type (catalogue)              Assessment (configured instance)
"CA", "Test", "Examination"    →         "CA 1" for Mathematics, First Term, JSS 2
                                          class_subject_id + term_id + assessment_type_id
```

An **Assessment Type** is a reusable category, structurally a twin of `Subject`: a flat,
globally unique, ordered catalogue retired with `CatalogStatus` rather than deleted.

An **Assessment** is one configured instance of a category against a specific class subject and
term - "Mathematics, First Term, JSS 2, CA 1". It references `class_subject_id` + `term_id`
directly; there is **no** `academic_session_id` column, because a term's own
`academic_session_id` already makes the session reachable through `term.academic_session` -
storing it twice would be the exact duplication `TeacherAssignment` already avoided by
referencing `class_subject_id` instead of `class_id`+`subject_id` separately.

**This module holds no score, grade or result column, and there is no scores table yet.**
Recording a pupil's mark against an assessment, and compiling marks into a grade or a result,
are later modules' concerns - this module configures **what** will be scored, not the scores
themselves. `max_score` and `weight` describe the assessment's own shape (its ceiling and its
share of the term's grade), never a pupil's performance against it.

**Several assessments of the same type may exist for the same class subject and term** - CA1,
CA2 and CA3 are all category `CA`, and all coexist. Uniqueness is on
`(class_subject_id, term_id, name)`, not on the type: the type alone cannot distinguish CA1
from CA2.

**Weights are not required to sum to 100.** Whether a grading scheme demands that is a policy
decision for whatever module compiles a term's grade, not a fact this configuration step can
enforce without knowing the whole scheme in advance.

---

## 1. Conventions

Inherited from the [API reference](README.md): bearer tokens, `Accept: application/json`, the
`{ "data": ..., "message": ... }` envelope, `meta`/`links` as siblings of `data`, and
200/201/401/403/404/405/422/429.

### 1.1 Assessment types have a delete endpoint; assessments do not

`DELETE /assessment-types/{id}` exists, guarded: refused with `422` while any assessment still
uses the category. `DELETE /assessments/{id}` is **not registered at all** - `405` with
`Allow: GET, HEAD, PUT`. An assessment is exactly the kind of academic-history anchor a future
score will reference to answer "what was this worth, and out of how much" - `status: INACTIVE`
through the ordinary `PUT` is the record-preserving replacement.

### 1.2 `PUT /assessments/{id}` never touches the academic context

`class_subject_id`, `term_id` and `assessment_type_id` have **no path to change** once
created - not merely unvalidated, structurally absent from the request. The triple an
assessment names is fixed for its lifetime; a wrongly configured assessment is retired via
`status` and a fresh `POST` replaces it.

---

## 2. The resources

```json
{
  "data": {
    "id": 7,
    "name": "Continuous Assessment",
    "code": "CA",
    "sort_order": 1,
    "status": "ACTIVE",
    "assessments_count": 3,
    "created_at": "2026-10-03T09:00:00+00:00",
    "updated_at": "2026-10-03T09:00:00+00:00"
  },
  "message": "..."
}
```

```json
{
  "data": {
    "id": 12,
    "class_subject": { "id": 5, "school_class": { "...": "..." }, "subject": { "...": "..." }, "status": "ACTIVE" },
    "term": { "id": 3, "name": "First Term", "status": "ACTIVE", "...": "..." },
    "assessment_type": { "id": 7, "name": "Continuous Assessment", "code": "CA", "...": "..." },
    "name": "CA 1",
    "max_score": "20.00",
    "weight": "10.00",
    "sort_order": 0,
    "status": "ACTIVE",
    "created_at": "2026-10-03T09:05:00+00:00",
    "updated_at": "2026-10-03T09:05:00+00:00"
  },
  "message": "..."
}
```

`max_score` and `weight` are returned as **decimal strings**, not floats, to preserve the exact
scale stored rather than risking float rounding. Every related record is rendered through its
own module's resource - `ClassSubjectResource` (itself nesting `SchoolClassResource`/
`SubjectResource`), `TermResource`, `AssessmentTypeResource` - so this module maintains no
second field list for any of them.

---

## 3. Endpoints

| Method | Path | Permission |
|---|---|---|
| `GET` | `/assessment-types` | `assessment_types.view` |
| `POST` | `/assessment-types` | `assessment_types.create` |
| `GET` | `/assessment-types/{id}` | `assessment_types.view` |
| `PUT` | `/assessment-types/{id}` | `assessment_types.update` |
| `DELETE` | `/assessment-types/{id}` | `assessment_types.delete` |
| `GET` | `/assessments` | `assessments.view` |
| `POST` | `/assessments` | `assessments.create` |
| `GET` | `/assessments/{id}` | `assessments.view` |
| `PUT` | `/assessments/{id}` | `assessments.update` |

All nine require an active bearer token (`auth:api` + `active`).

### 3.1 `GET /assessment-types`

| Parameter | Values | Notes |
|---|---|---|
| `status` | `ACTIVE`, `INACTIVE`, `ARCHIVED` | |
| `code` | string | Exact match, case-insensitive |
| `active_only` | `true`/`false` | The "choose a category" picker an assessment create form needs |
| `search` | string | Matches `name` |
| `per_page` | 1–100, default 15 | |

### 3.2 `POST /assessment-types`

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"name":"Continuous Assessment","code":"ca"}' \
  http://localhost:8000/api/v1/assessment-types
```

**201.** `code` is upper-cased before validation. `name` and `code` are unique across the whole
catalogue.

### 3.3 `DELETE /assessment-types/{id}`

Refused with `422` while **any** assessment references it, regardless of that assessment's own
status - an `INACTIVE` assessment is still a historical fact that once used the category.

### 3.4 `GET /assessments`

| Parameter | Values | Notes |
|---|---|---|
| `class_subject_id` | integer | Must exist - "what is configured for this class subject" |
| `term_id` | integer | Must exist - "what is configured for this term" |
| `assessment_type_id` | integer | Must exist - "which assessments are of this category" |
| `academic_session_id` | integer | Must exist - derived through `term`, not a stored column |
| `status` | `ACTIVE`, `INACTIVE`, `ARCHIVED` | |
| `per_page` | 1–100, default 15 | |

**There is no `search`** - an assessment's own `name` is short ("CA 1"), and the canonical
filters already answer the realistic question far more precisely than a text search across
every assessment in the school would; `?search=...` is rejected with `422`, not silently
ignored.

### 3.5 `POST /assessments`

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"class_subject_id":5,"term_id":3,"assessment_type_id":7,"name":"CA 1","max_score":20,"weight":10}' \
  http://localhost:8000/api/v1/assessments
```

**201.** Status is always `ACTIVE`.

**Every reference is checked against the database, never trusted from the client:**

- `class_subject_id` must exist and be `ACTIVE`, **and its class must be `ACTIVE`, and that
  class's class level must also be `ACTIVE`** - the identical multi-level guard Module 06,
  Module 07 and Module 08 each apply to their own class-hierarchy references.
- `term_id` must exist and must not be `COMPLETED`.
- `assessment_type_id` must exist and be `ACTIVE`.
- `name` must be unique **within the same class subject and term** - "CA 1" is an ordinary
  name to reuse across different class subjects or terms; only a genuine duplicate within the
  same academic context is refused.
- `max_score` must be greater than zero.
- `weight`, if sent, must be between 0 and 100 - but **is never checked against any other
  assessment's weight**. Configuring several assessments whose weights do not sum to 100 for
  the same class subject and term is allowed.

### 3.6 `PUT /assessments/{id}`

Accepts `name`, `max_score`, `weight`, `sort_order` and `status`. `class_subject_id`, `term_id`
and `assessment_type_id` are **not** accepted here at all.

---

## 4. Lifecycle

Both resources reuse `CatalogStatus` (`ACTIVE`/`INACTIVE`/`ARCHIVED`), a freely reversible
toggle set through the ordinary `PUT` - **not** a one-shot terminal workflow. "This assessment
is no longer offered" is the same kind of fact as `ClassSubject.status`, not a person or
placement permanently ending, so it needs no dedicated workflow endpoint of its own.

---

## 5. Permissions

| Permission | Grants |
|---|---|
| `assessment_types.view` | `GET /assessment-types`, `GET /assessment-types/{id}` |
| `assessment_types.create` | `POST /assessment-types` |
| `assessment_types.update` | `PUT /assessment-types/{id}` |
| `assessment_types.delete` | `DELETE /assessment-types/{id}` |
| `assessments.view` | `GET /assessments`, `GET /assessments/{id}` |
| `assessments.create` | `POST /assessments` |
| `assessments.update` | `PUT /assessments/{id}` |

**No `assessments.delete`** - no delete endpoint is registered.

### 5.1 Role grants

| Role | types `view` | types `create`/`update` | types `delete` | assessments `view` | assessments `create`/`update` |
|---|:--:|:--:|:--:|:--:|:--:|
| `SUPER_ADMIN` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `ADMIN` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `REGISTRAR` | ✓ | ✓ | — | ✓ | ✓ |
| `STAFF` | — | — | — | — | — |
| `STUDENT` | — | — | — | — | — |

`REGISTRAR` gets full CRUD minus delete, matching Module 07's split for `subjects.*` and
`class_subjects.*` - assessment configuration is curriculum structure, the same bucket
`RoleSeeder` already names among a registrar's duties, **not** the staffing-level decision
Module 08 narrows `REGISTRAR` to view-only for.

**`STAFF` holds nothing**, including teaching staff. Knowing which assessments exist for the
class subjects a teacher is assigned to is deliberately deferred to the future assessment
scores module, where ownership-scoped authorization (a teacher may act only on their own
assigned class subjects) will need to exist for the first time - building it now, ahead of the
module that actually needs it, would be speculative architecture this project's own conventions
caution against.

---

## 6. Client checklist

- [ ] Send `Accept: application/json` and a bearer token on every call.
- [ ] Always send all three references on create - `class_subject_id`, `term_id`,
      `assessment_type_id`.
- [ ] Expect `422` naming `name` if that exact name is already configured for the same
      class subject and term - **even under a different assessment type**.
- [ ] CA1, CA2, CA3 for the same class subject and term are three separate `POST` calls with
      three different `name` values, not one call with a count.
- [ ] Do not expect weights across a class subject's assessments to be validated as summing to
      100 - nothing in this module enforces that.
- [ ] Do not try to `DELETE` an assessment. `405`. Retire it with `PUT .../{id}` and
      `status: INACTIVE` instead.
- [ ] Do not send `class_subject_id`, `term_id` or `assessment_type_id` to `PUT
      /assessments/{id}` expecting them to change - they have no key to send.
- [ ] Do not send `search` to `GET /assessments` - filter by `class_subject_id`, `term_id`,
      `assessment_type_id`, `academic_session_id` or `status` instead.
- [ ] `academic_session_id` is a valid filter on `GET /assessments` even though it is not a
      stored column - it is resolved through each assessment's `term`.
