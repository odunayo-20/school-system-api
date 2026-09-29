# Result Approval & Publication API

Taking a compiled result through review to a final, immutable academic record.

Module 13. Four workflow endpoints, no new table, and one deliberate restriction: **this
module moves a result through its own status column - it never exposes a public
result-checking endpoint, prints a report card, or promotes a student.** See
[result-compilation.md](result-compilation.md) for how a result is compiled in the first
place; this document covers only what happens to it afterward.

- [0. Read this first: the pipeline, and what this module does not do](#0-read-this-first-the-pipeline-and-what-this-module-does-not-do)
- [1. Conventions](#1-conventions)
- [2. Endpoints](#2-endpoints)
- [3. The workflow](#3-the-workflow)
- [4. Historical data safety](#4-historical-data-safety)
- [5. Permissions and separation of duties](#5-permissions-and-separation-of-duties)
- [6. Client checklist](#6-client-checklist)

---

## 0. Read this first: the pipeline, and what this module does not do

```
COMPILED --submit--> SUBMITTED --approve--> APPROVED --publish--> PUBLISHED --lock--> LOCKED
   ^                                                                                     |
   |                                                                                     v
   +-------------------------- recompile (COMPILED/INCOMPLETE only) --------- refused, 422
```

There is **no separate `DRAFT` status and no new table**. `COMPILED` (Module 12's own status
for a fully-scored result) already plays the role a `DRAFT` status would: a result is either
not yet complete (`INCOMPLETE`), complete and awaiting submission (`COMPILED`), or somewhere
in this module's own pipeline. The whole lifecycle lives on the same `results.status` column
Module 12 introduced, now with three more cases: `SUBMITTED`, `APPROVED`, `PUBLISHED` (`LOCKED`
already existed, reserved unused until now).

**The pipeline is strictly linear and forward-only.** There is no reject-back-to-draft
transition and no unpublish/unlock. A result that was submitted in error is not reachable by
this module's own endpoints once submitted - see §3.5 for why this was a deliberate choice,
not an oversight.

**This module does not expose any public, student-facing result-checking endpoint.** A
`PUBLISHED` result is the hand-off point a future **Result Checker** module (Module 16) is
expected to read from; nothing here lets a student, or anyone without `results.view`, read a
result at all. **Report Cards, Promotion, Attendance, Timetable and Notifications** remain out
of scope, exactly as Module 12 already stated for itself.

---

## 1. Conventions

Inherited from the [API reference](README.md) and
[result-compilation.md](result-compilation.md) §1: bearer tokens, `Accept: application/json`,
the `{ "data": ..., "message": ... }` envelope, and 200/401/403/404/422/429.

### 1.1 Every workflow endpoint takes no request body at all

Not even a Form Request. `POST /results/{id}/submit|approve|publish|lock` reads nothing but
the route-bound result and the authenticated caller. Sending `{"status": "PUBLISHED"}` (or any
other field) to any of these has **no effect whatsoever** - the server alone ever determines
the next state. This mirrors `POST /admissions/{id}/admit`'s own identical shape for a
transition that needs no client-supplied reason.

### 1.2 Every transition answers `200`, never `201`, and is never idempotent

A transition amends the existing result row; nothing is created. Repeating one - submitting an
already-`SUBMITTED` result, approving twice, or attempting one out of order - is refused with
**422**, never silently treated as a no-op success. See §3.4.

---

## 2. Endpoints

| Method | Path | Permission | Required prior status |
|---|---|---|---|
| `POST` | `/results/{id}/submit` | `results.submit` | `COMPILED` |
| `POST` | `/results/{id}/approve` | `results.approve` | `SUBMITTED` |
| `POST` | `/results/{id}/publish` | `results.publish` | `APPROVED` |
| `POST` | `/results/{id}/lock` | `results.lock` | `PUBLISHED` |

All four require an active bearer token (`auth:api` + `active`) and return the full
[result resource](result-compilation.md#2-the-result-resource) on success.

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  http://localhost:8000/api/v1/results/41/submit
```

```json
{
  "data": {
    "id": 41,
    "status": "SUBMITTED",
    "submitted_by": { "id": 9, "name": "Amaka Obi" },
    "submitted_at": "2026-10-07T09:00:00+00:00",
    "approved_by": null,
    "approved_at": null,
    "...": "every other field from the result resource"
  },
  "message": "Result submitted for approval."
}
```

A result not in the required prior status is refused with **422** and a message naming both
the result's actual status and what it must be first, e.g.:

```json
{ "message": "This result is COMPILED, so it cannot be approved. It must be SUBMITTED first." }
```

A result that is `INCOMPLETE` gets a more specific refusal when submission is attempted:

```json
{ "message": "This result is INCOMPLETE - not every assessment has a score yet - so it cannot be submitted." }
```

An id that does not exist at all is **404**, exactly as for `GET /results/{id}`.

---

## 3. The workflow

### 3.1 Submit (`COMPILED` → `SUBMITTED`)

The only transition `STAFF` can perform, and the only one scoped the same way `compile()`
already is: a teaching staff member may submit only a result for a class subject they hold an
`ACTIVE` `TeacherAssignment` for, for the term's own academic session -
[result-compilation.md](result-compilation.md) §5.1's identical scope, reused unchanged.
`ADMIN`/`REGISTRAR`/`SUPER_ADMIN` are unrestricted.

Records `submitted_by` (the acting user) and `submitted_at` (now).

### 3.2 Approve (`SUBMITTED` → `APPROVED`)

No teacher scope - only `SUPER_ADMIN` and `ADMIN` hold `results.approve` at all (§5). Records
`approved_by`/`approved_at`.

### 3.3 Publish (`APPROVED` → `PUBLISHED`)

Only `SUPER_ADMIN` and `ADMIN`. Records `published_by`/`published_at`. This is the hand-off
point: a `PUBLISHED` result is what a future Result Checker module is expected to read, though
nothing in this module exposes it publicly yet.

### 3.4 Lock (`PUBLISHED` → `LOCKED`)

Only `SUPER_ADMIN` and `ADMIN`. Records `locked_by`/`locked_at`. **Terminal** - once `LOCKED`,
no further transition exists, and `POST /results/compile`/`POST /results/bulk` both refuse to
recompile it (§4). There is no unlock endpoint.

### 3.5 Why there is no reject or reverse transition

Considered and deliberately not built. The brief's own workflow diagram is strictly linear;
adding a `SUBMITTED → COMPILED` "send back for correction" transition, or an
`APPROVED/PUBLISHED → SUBMITTED` unwind, would be new domain logic (what happens to
`approved_by`/`published_at` on a reversal? does a reversed result need its own history?) with
no requirement asking for it. A result that needs to go back for correction today is reachable
only by an administrative decision outside this API - the minimal, honest answer until a real
requirement defines what "reject" should mean. See the [Module 13 audit](../audits/module-13-result-approval-publication-audit.md)
§3 for the full reasoning.

---

## 4. Historical data safety

**Once a result leaves `COMPILED`/`INCOMPLETE`, `POST /results/compile` and
`POST /results/bulk` both refuse to touch it, with 422** - not only once `LOCKED`, as Module 12
originally built, but from `SUBMITTED` onward. A later score correction, therefore, has **zero
effect** on a result that has already been submitted, approved, published or locked: the
underlying `Score` can change freely, but the `Result` snapshot it once fed is now frozen until
an explicit, later recompile is possible again - which, for a `SUBMITTED`-or-later result,
means never, short of a correction mechanism this module does not build.

The same guarantee protects against a later **grading-scale** change (Module 11) or
**assessment configuration** change (Module 09): neither is ever read again for an existing
result row, because nothing recalculates a result outside an explicit compile, and compile
itself is now refused past `COMPILED`. This is Module 12's own snapshot architecture,
unmodified - Module 13 only tightens the one guard that already existed for `LOCKED`.

---

## 5. Permissions and separation of duties

| Permission | Grants |
|---|---|
| `results.submit` | `POST /results/{id}/submit` |
| `results.approve` | `POST /results/{id}/approve` |
| `results.publish` | `POST /results/{id}/publish` |
| `results.lock` | `POST /results/{id}/lock` |

### 5.1 Role grants

| Role | `submit` | `approve` | `publish` | `lock` |
|---|:--:|:--:|:--:|:--:|
| `SUPER_ADMIN` | ✓ | ✓ | ✓ | ✓ |
| `ADMIN` | ✓ | ✓ | ✓ | ✓ |
| `REGISTRAR` | ✓ | — | — | — |
| `STAFF` | ✓ (scoped) | — | — | — |
| `STUDENT` | — | — | — | — |

**Separation of duties is enforced structurally, by which roles hold which permission - never
by a runtime "you cannot approve your own submission" check.** `STAFF` and `REGISTRAR` can
compile and submit a result, but **neither holds `results.approve`, `.publish` or `.lock` at
all** - so a teacher (or a registrar) can never be the one who signs off on their own
submission, regardless of who actually submitted it. `ADMIN` holds all four: this project has
no maker-checker pattern anywhere else (the same `ADMIN` who admits an applicant also created
the admission; the same `ADMIN` who creates a staff record also amends it), so requiring a
*different* `ADMIN` to approve would be new complexity this project's own conventions do not
otherwise ask for. See the [Module 13 audit](../audits/module-13-result-approval-publication-audit.md)
§7 for the full reasoning.

**Why `REGISTRAR` and `STAFF` stop at `submit`.** Compiling and submitting are mechanical
execution of an already-configured process - the same "admissions, enrollment and student
records" territory `RoleSeeder` names for `REGISTRAR`. Approving and publishing are an
academic-oversight *decision* that a result is final enough to stand as the school's own
record - the same kind of policy judgment Module 11 withheld from `REGISTRAR` over grade
boundaries.

---

## 6. Client checklist

- [ ] Send `Accept: application/json` and a bearer token on every call.
- [ ] Every workflow endpoint is a bare `POST` with **no body** - sending `status` or any other
      field has no effect; the server alone decides the next state.
- [ ] Expect `422` for a transition attempted out of order (submitting a `COMPILED` result
      twice, publishing before approval, etc.) - the message names the result's current status
      and what it must be first.
- [ ] A result cannot be submitted while `INCOMPLETE` - every configured assessment needs a
      score first. Check `status` before offering "submit" in a UI.
- [ ] There is no reject/reverse transition. A `SUBMITTED`-or-later result cannot be recompiled
      or sent back to `COMPILED` through this API.
- [ ] `submitted_by`/`approved_by`/`published_by`/`locked_by` are small `{id, name}` actor
      objects, not the full user record - do not expect an email or a permission list there.
- [ ] A `LOCKED` result is permanently immutable: no further transition, and
      `PUT`/`PATCH`/`DELETE` on `/results/{id}` remain `405`, exactly as for any other result.
- [ ] `results.approve`/`.publish`/`.lock` are never granted to the same roles that hold
      `results.submit` beyond `ADMIN`/`SUPER_ADMIN` - do not build a UI that assumes a teacher
      or registrar can approve their own submission.
