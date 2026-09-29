# Grading API

How a raw percentage is interpreted as a grade, grade point and remark.

Module 11. Five endpoints, two tables, and one deliberate restriction: **this module
interprets a percentage - it never compiles a result, calculates a weighted total, or
publishes anything.**

- [0. Read this first: scale, bands, and what this module does not do](#0-read-this-first-scale-bands-and-what-this-module-does-not-do)
- [1. Conventions](#1-conventions)
- [2. The grading scale resource](#2-the-grading-scale-resource)
- [3. Endpoints](#3-endpoints)
- [4. Boundary behaviour](#4-boundary-behaviour)
- [5. Permissions](#5-permissions)
- [6. Client checklist](#6-client-checklist)

---

## 0. Read this first: scale, bands, and what this module does not do

```
GradingScale ("Junior Secondary Standard")   scoped to ONE class level
  └─ GradingScaleItem  70.00–100.00  → A  grade point 5.00  "Excellent"
  └─ GradingScaleItem  60.00–69.99   → B  grade point 4.00  "Very Good"
  └─ GradingScaleItem  0.00–59.99    → F  grade point 0.00  "Fail"
```

A **Grading Scale** is a reusable scheme scoped to exactly one **class level** (Nursery,
Primary, Junior Secondary, Senior Secondary, or whatever stages the school's own
`class_levels` table holds). A **Grading Scale Item** ("band") is one percentage range within
a scale, with the grade, grade point and remark that range maps to. Bands are always read and
written together, as one scale - there is no separate `/grading-scale-items` resource; see §3.

`class_level_id` is **required**, not nullable. A "whole-school default" scope was
considered and rejected: this project's single-active-record technique (already used by
`School`, `AcademicSession`, `Term`, and Module 08's `TeacherAssignment`) relies on a
composite unique index where every SCOPING column is `NOT NULL` - standard SQL does not treat
two `NULL`s as equal for uniqueness (SQL Server is the sole, inconsistent exception), so a
nullable scope would make "at most one active scale per scope" silently unenforceable on this
project's other three configured drivers. A school wanting one uniform scheme across levels
defines the same bands under each level's own scale.

**This module does not implement grading letters as hardcoded rules, GPA compilation, subject
result totals, report cards, or promotion.** Every grade, grade point and remark is data an
administrator configures, never a value in source code. A future Result Compilation module
consumes this module's `calculate` operation; it is not built here.

---

## 1. Conventions

Inherited from the [API reference](README.md): bearer tokens, `Accept: application/json`, the
`{ "data": ..., "message": ... }` envelope, `meta`/`links` as siblings of `data`, and
200/201/401/403/404/405/422/429.

### 1.1 There is no delete endpoint

`DELETE /grading-scales/{id}` is not registered - **405** with `Allow: GET, HEAD, PUT`. A scale
is exactly the kind of academic-policy anchor a future grading/result-compilation module will
reference to interpret a historical result. Retiring one is `status: INACTIVE`/`ARCHIVED`
through the ordinary `PUT`, not a delete.

### 1.2 `PUT` never touches `class_level_id`

Not merely unvalidated - structurally absent from the request. The class level a scale applies
to is fixed for its lifetime.

### 1.3 `PUT` always replaces the whole band set

There is no per-band `POST`/`PUT`/`DELETE`. Every amend resubmits the **complete** `items`
array; the previous bands are deleted and the submitted set is inserted, inside one
transaction. Overlap, minimum/maximum and duplicate-grade checks only make sense against a
scale's bands as a whole, so bands are never edited one at a time.

---

## 2. The grading scale resource

```json
{
  "data": {
    "id": 3,
    "name": "Junior Secondary Standard",
    "code": "JSS-STD",
    "sort_order": 0,
    "status": "ACTIVE",
    "class_level": { "id": 5, "name": "Junior Secondary", "code": "JSS", "status": "ACTIVE" },
    "items": [
      { "id": 12, "grade": "A", "min_percentage": "70.00", "max_percentage": "100.00", "grade_point": "5.00", "remark": "Excellent" },
      { "id": 13, "grade": "B", "min_percentage": "60.00", "max_percentage": "69.99", "grade_point": "4.00", "remark": "Very Good" }
    ],
    "created_at": "2026-10-05T09:00:00+00:00",
    "updated_at": "2026-10-05T09:00:00+00:00"
  },
  "message": "..."
}
```

`items` is present only on `POST`, `PUT` and `GET /{id}` - the **list** endpoint omits it
entirely (no `items` key at all), so browsing many scales stays a small, flat read; call
`GET /grading-scales/{id}` to see a specific scale's full band configuration.

`min_percentage`, `max_percentage` and `grade_point` are decimal strings, not floats -
matching `assessments.max_score`'s own precedent from Module 09, so a boundary like `69.99` is
never subject to binary floating-point rounding.

---

## 3. Endpoints

| Method | Path | Permission |
|---|---|---|
| `GET` | `/grading-scales` | `grading_scales.view` |
| `POST` | `/grading-scales` | `grading_scales.create` |
| `GET` | `/grading-scales/{id}` | `grading_scales.view` |
| `PUT` | `/grading-scales/{id}` | `grading_scales.update` |
| `POST` | `/grading-scales/{id}/calculate` | `grading_scales.view` |

All five require an active bearer token (`auth:api` + `active`).

### 3.1 `GET /grading-scales`

| Parameter | Values | Notes |
|---|---|---|
| `class_level_id` | integer | Must exist |
| `status` | `ACTIVE`, `INACTIVE`, `ARCHIVED` | |
| `active_only` | `true`/`false` | The "choose a scale" picker a future result module's admin screen will need |
| `search` | string | Matches `name` |
| `per_page` | 1–100, default 15 | |

### 3.2 `POST /grading-scales`

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"class_level_id":5,"name":"Junior Secondary Standard","code":"jss-std","items":[
        {"grade":"A","min_percentage":70,"max_percentage":100,"grade_point":5,"remark":"Excellent"},
        {"grade":"F","min_percentage":0,"max_percentage":69.99,"grade_point":0,"remark":"Fail"}
      ]}' \
  http://localhost:8000/api/v1/grading-scales
```

**201.** Status is always `ACTIVE`.

**Every reference and every band is checked, never trusted blindly:**

- `class_level_id` must exist and be `ACTIVE`.
- `name`/`code` unique **within the same class level**, not globally - two different class
  levels may each have their own "Standard" scale.
- **At most one `ACTIVE` scale per class level.** A second `POST` that would create one, or a
  `PUT` that reactivates one while another is already active for the same class level, is
  refused with `422`.
- `items` requires at least one band. Each band's `min_percentage`/`max_percentage` must be
  between 0 and 100, `min_percentage` must not exceed `max_percentage`, and **no two bands in
  the same scale may overlap** (inclusive at both ends - see §4) or **share the same grade**.

### 3.3 `PUT /grading-scales/{id}`

Accepts `name`, `code`, `sort_order`, `status`, and the full `items` array (always required -
`PUT` is a whole-record write). `class_level_id` is not accepted at all.

### 3.4 `POST /grading-scales/{id}/calculate`

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"percentage":85}' \
  http://localhost:8000/api/v1/grading-scales/3/calculate
```

**200.** Read-only and side-effect-free - it never creates, reads or amends a `Score` or any
future `Result`. The percentage is the **only** input; a client cannot send `grade` or
`grade_point` and expect either to influence the answer - the server always calculates every
derived value from the scale's own live configuration.

```json
{
  "data": {
    "percentage": 85,
    "grade": "A",
    "grade_point": "5.00",
    "remark": "Excellent",
    "matched_band": { "id": 12, "grade": "A", "min_percentage": "70.00", "max_percentage": "100.00", "grade_point": "5.00", "remark": "Excellent" }
  }
}
```

**No matching band is not an error.** If the scale has a gap and the given percentage falls
inside it, the response is still `200`, with `grade`, `grade_point`, `remark` and
`matched_band` all `null` - never a guess, never a silently wrong grade.

---

## 4. Boundary behaviour

Every band is **inclusive at both ends**: `min_percentage <= percentage <= max_percentage`.
Two adjacent, non-overlapping bands are therefore written with the upper band starting exactly
where the lower one's precision allows it to stop - `60.00`–`69.99` and `70.00`–`100.00`, not
`60`–`70` and `70`–`100`, which would double-cover `70.00` itself and is refused as an overlap.

| Percentage | With bands `70–100 → A`, `60–69.99 → B` |
|---|---|
| `69.99` | `B` |
| `70.00` | `A` |
| `70.01` | `A` |

**Gaps are allowed.** A school may legitimately leave a range undefined (for example, no band
below 40 at all). A gap is not rejected at configuration time; it is made safe at calculation
time instead - see §3.4.

---

## 5. Permissions

| Permission | Grants |
|---|---|
| `grading_scales.view` | `GET /grading-scales`, `GET /grading-scales/{id}`, `POST /grading-scales/{id}/calculate` |
| `grading_scales.create` | `POST /grading-scales` |
| `grading_scales.update` | `PUT /grading-scales/{id}` |

**No `grading_scales.delete`** - no delete endpoint is registered. **No separate `.calculate`
permission** - the calculation endpoint is a read-only preview of a scale's own configuration.

### 5.1 Role grants

| Role | `view` (incl. calculate) | `create` | `update` |
|---|:--:|:--:|:--:|
| `SUPER_ADMIN` | ✓ | ✓ | ✓ |
| `ADMIN` | ✓ | ✓ | ✓ |
| `REGISTRAR` | ✓ | — | — |
| `STAFF` | — | — | — |
| `STUDENT` | — | — | — |

**`REGISTRAR` holds `view` only** - matching Module 08's split for `teacher_assignments.*`
rather than Module 09's split for `assessments.*`: defining grade boundaries is school
ACADEMIC POLICY, further still from `RoleSeeder`'s own stated registrar duties ("admissions,
enrollment and student records") than assessment configuration already was.

**`STAFF` holds nothing, including teaching staff who enter scores (Module 10).** Entering a
raw score never implies a say over what that score means - the brief's own explicit boundary.

---

## 6. Client checklist

- [ ] Send `Accept: application/json` and a bearer token on every call.
- [ ] Always send the full `items` array on `PUT` - a partial band list is not a partial
      update, it REPLACES every existing band.
- [ ] Write adjacent bands with the smallest representable gap at the shared boundary
      (`60.00`–`69.99` next to `70.00`–`100.00`), not touching ends (`60`–`70` next to
      `70`–`100`) - the latter is refused as an overlap.
- [ ] Expect `422` if you try to activate a second scale for a class level that already has
      one active - end (retire) the old one first.
- [ ] Do not try to `DELETE` a grading scale. `405`. Retire it with `PUT .../{id}` and
      `status: INACTIVE` instead.
- [ ] Do not send `class_level_id` to `PUT /grading-scales/{id}` expecting it to change - it
      has no key to send.
- [ ] `POST /grading-scales/{id}/calculate` never mutates anything - call it as often as you
      like for a live preview while configuring bands.
- [ ] A `null` `grade` from `calculate` means the percentage falls in a gap, not an error -
      check for `null` explicitly rather than assuming a grade is always present.
