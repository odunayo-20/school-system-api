# School Configuration & Academic Foundation API

**Module:** 02 - School Configuration & Academic Foundation
**Base URL:** `/api/v1`
**Auth scheme:** `Authorization: Bearer <token>` (Laravel Sanctum personal access tokens)

Covers the single school profile, the academic year (session), its terms, the combined
current context, and the three-level class structure: class levels, classes, sections.

---

## 1. Conventions

### 1.1 Response envelope

**Single resource**

```json
{
  "data": { "id": 1, "name": "2026/2027", "...": "..." },
  "message": "Academic session updated."
}
```

**List**

```json
{
  "data": [ { "id": 1, "...": "..." } ],
  "meta": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 15,
    "from": 1,
    "to": 15,
    "total": 42
  },
  "links": {
    "first": "http://.../api/v1/sessions?page=1",
    "last":  "http://.../api/v1/sessions?page=3",
    "prev":  null,
    "next":  "http://.../api/v1/sessions?page=2"
  }
}
```

`data` is always the list. `links` and `meta` are its **siblings**, never nested inside
`data`, so one code path reads every endpoint in the API. This differs from Laravel's
default paginated resource response, which nests them one level deeper.

**No content / explicit null**

Two different shapes, deliberately not the same:

| Case | Body |
|---|---|
| Operation succeeded, nothing to return (delete, logout) | `{ "message": "..." }` - no `data` key |
| The absence of a resource *is* the answer | `{ "data": null, "message": "..." }` |

**Failure**

```json
{
  "message": "The given data was invalid.",
  "errors": { "name": ["An academic session with this name already exists."] }
}
```

`errors` is present only for validation failures (422).

### 1.2 Status codes

| Code | Meaning |
|---|---|
| 200 | Read or update succeeded |
| 201 | Resource created |
| 401 | No token, invalid token, or the token was revoked |
| 403 | Authenticated, but the account is suspended or lacks the permission |
| 404 | No such record, or the route does not accept the method |
| 422 | Validation failed, or a business rule refused the change |
| 429 | Rate limited (login and password reset only) |

A 404 for a missing record is always `{"message":"Resource not found."}` - the API does not
distinguish "does not exist" from "exists but you may not see it", because there is nothing
else for a single-school API to hide.

### 1.3 Statelessness

Every request carries a bearer token; no cookies, no CSRF token, no session. Send
`Accept: application/json` on every call - without it Laravel redirects a validation
failure to an HTML error page instead of returning JSON.

---

## 2. The school profile

The school is a **singleton**. There is exactly one row, enforced by a unique index on
`singleton_key`. That is why there is no `POST /school`: the seeder creates the record, and
`PUT` is the only way to change it.

`singleton_key` is never exposed. It is a structural constant, and publishing an internal
uniqueness token invites clients to depend on it.

| Field | Type | Notes |
|---|---|---|
| `id` | int | |
| `name` | string | Required on update. Max 150 |
| `short_name` | string | Required on update. Max 50. Stored upper case, so `gfs` reads back `GFS` |
| `motto` | string? | |
| `email` | string? | |
| `phone` | string? | |
| `alternate_phone` | string? | |
| `website` | string? | Must include a scheme. Normalised: `HTTPS://X.Example` stores `https://x.example`. Blank stores `null`, never `""` |
| `address_line1` | string? | |
| `city` | string? | |
| `state` | string? | |
| `country` | string? | |
| `principal_name` | string? | |
| `registration_number` | string? | **Not unique** - two schools in different countries may share one |
| `status` | enum | `ACTIVE`, `INACTIVE` |
| `created_at`, `updated_at` | ISO 8601 | |

`SchoolStatus` is deliberately not `UserStatus`. `UserStatus` describes a person and
carries `SUSPENDED`, which revokes tokens; this describes a configuration record.

### `GET /school`

Permission: `school.view`

```json
{ "data": { "id": 1, "name": "Greenfield International School", "short_name": "GIS" } }
```

### `PUT /school`

Permission: `school.update`. Accepts `PUT` only - `PATCH` returns 405.

`name` and `short_name` are both **required** on every call. This is a whole-record update,
not a partial one: omitting a required field is a 422 rather than a silent clear.

```http
PUT /api/v1/school
{ "name": "Greenfield International School", "short_name": "GIS", "motto": "Knowledge and character" }
```

---

## 3. Academic context

### `GET /academic-context`

Permission: `academic_sessions.view`

One read of "what year and term is this school in", so a client can render
`2026/2027 - First Term` with one request instead of three.

```json
{
  "data": {
    "school":  { "id": 1, "name": "Greenfield International School", "short_name": "GIS", "status": "ACTIVE" },
    "session": { "id": 1, "name": "2026/2027", "start_date": "2026-09-01", "end_date": "2027-08-31", "status": "ACTIVE" },
    "term":    { "id": 1, "academic_session_id": 1, "name": "First Term", "term_number": 1, "start_date": "...", "end_date": "...", "status": "ACTIVE" }
  },
  "message": "Current academic context."
}
```

Every part may be `null`, and the parts are independent - a school can have a profile and no
session yet, and a session can have no running term. The `message` tells the two cases
apart:

| `message` | Meaning |
|---|---|
| `Current academic context.` | A profile, a current session and a current term all exist |
| `The school academic setup is incomplete. ...` | At least one is missing - an installation problem, or an ordinary September |

This endpoint reads fresh on every call. It is not memoised per request, so it can never
report a stale value after a write in the same request.

---

## 4. Academic sessions

An academic session is the school year. `status` is **derived, never supplied** - see
[4.4](#44-why-status-is-not-an-input).

`UPCOMING` -> `ACTIVE` -> `COMPLETED`. Exactly one session is `ACTIVE` at any moment.

| Field | Type | Notes |
|---|---|---|
| `id` | int | |
| `name` | string | Unique. Max 50. **Normalised**: `2026 - 2027`, `2026-2027` and `2026/2027` all store as `2026/2027` |
| `start_date` | date | |
| `end_date` | date | Must be after `start_date` |
| `status` | enum | `UPCOMING`, `ACTIVE`, `COMPLETED` |
| `is_current` | bool | Same information as `status == "ACTIVE"`, for clients that prefer a flag |
| `created_at`, `updated_at` | ISO 8601 | |

The normalisation happens **before** the uniqueness check, not after. If the model folded
the separator later, a second session named `2026/2027` would pass the check and then
collide on the unique index, reporting a client mistake as a 500.

### 4.1 `GET /academic-sessions`

Permission: `academic_sessions.view`

| Query | Type | Notes |
|---|---|---|
| `status` | enum | `UPCOMING`, `ACTIVE`, `COMPLETED`. Invalid value is a 422 |
| `search` | string | Case-insensitive match on name. Max 120 |
| `per_page` | int | 1-100, default 15 |

Ordered by `start_date` descending: the question an administrator opens this screen to
answer is "what is running now", and the current session is not the newest by date once a
future session exists.

There is no `sort` parameter. Each endpoint has one sensible order chosen in the service;
a caller-chosen column would be an injection surface for no benefit at this size.

### 4.2 `POST /academic-sessions`

Permission: `academic_sessions.create`

```json
{ "name": "2027 - 2028", "start_date": "2027-09-01", "end_date": "2028-08-31" }
```

`201`, created as `UPCOMING`. `message` tells you the next step.

### 4.3 `GET|PUT|PATCH|DELETE /academic-sessions/{academicSession}`

Permissions: `academic_sessions.view` / `.update` / `.delete`

`PUT` and `PATCH` are both accepted and behave identically. Amending accepts the same
fields as creating; `name` is ignored against the record being amended, so re-saving a
session with its own name does not collide with itself.

`DELETE` is refused with a 422 when the session is the current one, has terms, or has
anything later modules have referenced. Completed history is retired, not erased.

### 4.4 Why `status` is not an input

Making a session current necessarily completes the one before it. If a client could also
post `status: "ACTIVE"` on a create or an update, it could produce a second active session,
and the database would answer that with an integrity error rendered as a **500** - a rule
violation reported as a server fault, for something the client had no way of knowing was
wrong. The only route to that transition is `POST .../activate`.

`activate` is a `POST` rather than a `PATCH` because it is a state transition with a side
effect beyond the record it names. It is also granted as its own permission
(`academic_sessions.activate`), independently of the ability to rename a session.

### `POST /academic-sessions/{academicSession}/activate`

Permission: `academic_sessions.activate`

Transactional. Completes the previously current session and makes this one current. One of:

| Situation | Result |
|---|---|
| Another session was current | `200`. Previous session is now `COMPLETED` |
| None was current | `200`. First activation |
| Target already current | `200`, no-op |
| Target is `COMPLETED` | `422` - a finished year cannot be reopened |

The unique index on `active_marker` is the second line of defence: even a bug in the
service cannot leave two current sessions.

---

## 5. Terms

A term belongs to exactly one session. `status` is derived, exactly as for sessions.

`UPCOMING` -> `ACTIVE` -> `COMPLETED`. **At most one term is `ACTIVE` across the whole
school**, and the active term must belong to the current session.

| Field | Type | Notes |
|---|---|---|
| `id` | int | |
| `academic_session_id` | int | Set by the route, never from the body |
| `academic_session` | object? | Present when the relation is loaded: `id`, `name`, `status` |
| `name` | string | Required. Max 100 |
| `term_number` | int | Required, 1-20. **Unique within its session**, not school-wide |
| `start_date` | date | Must fall inside the session's dates |
| `end_date` | date | After `start_date`, also inside the session's dates |
| `status` | enum | `UPCOMING`, `ACTIVE`, `COMPLETED` |
| `is_current` | bool | |
| `created_at`, `updated_at` | ISO 8601 | |

`term_number` is what orders the year, which is why it is a number and not a label. "Third
Term" cannot sort; `3` can. It is unique *within a session* because term 1 recurs every
year.

### 5.1 `GET /academic-sessions/{academicSession}/terms`

Permission: `terms.view`

Filters: `status`, `search`, `per_page` (1-100, default 15).

### 5.2 `POST /academic-sessions/{academicSession}/terms`

Permission: `terms.create`

```json
{ "name": "First Term", "term_number": 1, "start_date": "2026-09-01", "end_date": "2026-12-18" }
```

`201`, created as `UPCOMING`.

The session comes from the URL and is never read from the body, so a term cannot be filed
under a different session than the one being addressed - the service treats the method's
session as authoritative even if a body disagrees.

**Dates outside the session are refused with a 422.** This rule needs the session, so it
lives in `TermService` rather than the request, and therefore holds for any caller that
bypasses HTTP.

### 5.3 `GET|PUT|PATCH|DELETE /terms/{term}`

Permissions: `terms.view` / `.update` / `.delete`

Read and write use different URL shapes on purpose: reading a year is always "show me this
session's terms", while changing a term is "change this term", and a term carries its own
session id so a flat URL can resolve it.

`term_number` is ignored against the record being amended. Moving a term to another session
is not supported.

`DELETE` is refused with a 422 for the current term, or for a term with any later reference.

### `POST /terms/{term}/activate`

Permission: `terms.activate`

| Situation | Result |
|---|---|
| Target term's session is the current session | `200`, previous term becomes `COMPLETED` |
| Target term's session is not current | `422` - activate the session first |
| Another session is current | `422` - two sessions cannot both be current |
| Target already current | `200`, no-op |
| Target is `COMPLETED` | `422` |

---

## 6. Class levels, classes and sections

A three-level hierarchy. All three share one status enum and one set of rules.

```
class_levels  (Nursery, Primary, JSS)
   └── classes  (Nursery 1, Primary 5)
          └── sections  (Primary 5A)
```

`status` **is** client-settable here, unlike sessions and terms. None of the three is a
singleton, so writing a status can never violate a uniqueness constraint, and all three
genuinely need retiring by hand: a level is `ARCHIVED` when the school stops running it, a
class is `INACTIVE` while a cohort is not being taught.

| Value | Meaning |
|---|---|
| `ACTIVE` | In use, selectable when creating a child record |
| `INACTIVE` | Temporarily out of use |
| `ARCHIVED` | Permanently retired, still readable for historical data |

There is no `activate` endpoint and no archive endpoint for any of the three. "The current
one" is not a concept that applies, and retiring a record is an ordinary amend - a second
way to set it would be two code paths for one meaning.

### 6.1 Shared field shape

| Field | Type | Notes |
|---|---|---|
| `id` | int | |
| `name` | string | Required. Max 100 |
| `code` | string | Required. Max 20. Stored upper case. **Unique within the parent** |
| `sort_order` | int? | 0-9999. Defaults to 0 |
| `status` | enum | `ACTIVE`, `INACTIVE`, `ARCHIVED`. Defaults to `ACTIVE` |
| `created_at`, `updated_at` | ISO 8601 | |

`code` is the short identifier used in lists and reports. It is normalised to upper case
**before** the uniqueness check, for the same reason session names are: folding afterwards
would let `pri` pass against an existing `PRI` and then fail on the index as a 500.

### 6.2 Class levels

`GET /class-levels` - `class_levels.view`

Filters: `status`, `search`, `per_page`, `active_only`.

`active_only=true` returns only `ACTIVE` levels. It backs the "choose a class level"
picker, which must offer only levels a new class can actually be created in.

`active_only` accepts `1`, `0`, `true`, `false`. **This matters:** query parameters arrive
as strings and Laravel's `when()` branches on plain truthiness, so the string `"false"` is
truthy. Passing the raw parameter through made `?active_only=false` return exactly what
`?active_only=true` returned - a filter that could not be switched off. Any other value
(`?active_only=maybe`) is a 422.

`POST /class-levels` - `class_levels.create`.
`GET|PUT|PATCH|DELETE /class-levels/{classLevel}` - `.view` / `.update` / `.delete`.

`name` and `code` are unique outright. The show response adds `classes_count`.

`DELETE` is refused with a 422 when any class belongs to the level. These three tables are
the target of a foreign key from every later module, and a deleted level would take its
classes with it and orphan whatever the student module had filed against them.

### 6.3 Classes

`GET /classes` - `classes.view`

Filters: `status`, `search`, `per_page`, `active_only`, `class_level_id`.

`POST /classes` - `classes.create`

```json
{ "class_level_id": 1, "name": "Primary 5", "code": "p5", "sort_order": 5 }
```

The level is accepted in the **body** rather than taken from a route segment, so the whole
catalog shares one URL space. A missing or retired level is reported as a field error
(422), not as a 500 from a failed foreign key.

`GET|PUT|PATCH|DELETE /classes/{schoolClass}` - `.view` / `.update` / `.delete`.

| Extra field | Notes |
|---|---|
| `class_level_id` | Required on create, optional on amend |
| `class_level` | Present when loaded: `id`, `name`, `code` |
| `sections_count` | On the show response |

`name` and `code` are unique **within the level**. "A" in Primary 5 and "A" in JSS 1 are
unrelated rows. On amend the uniqueness scope follows the level the class *ends up* in, so
moving a class into a level that already holds its name is refused rather than colliding.

**A class cannot be created in, or moved into, a retired level.** Structure filed under a
retired parent is structure no picker offers and no one can reach. This is enforced in the
request *and* in the service, so the rule holds for any caller that bypasses HTTP.

Amending other fields on a class whose level has since been retired is still allowed -
archiving a level does not retroactively make everything under it immutable, and it does
not empty the level.

`DELETE` is refused with a 422 when any section belongs to the class.

### 6.4 Sections

`GET /sections` - `sections.view`

Filters: `status`, `search`, `per_page`, `active_only`, `school_class_id`.

`POST /sections` - `sections.create`

```json
{ "school_class_id": 1, "name": "A", "code": "a", "sort_order": 1 }
```

`GET|PUT|PATCH|DELETE /sections/{section}` - `.view` / `.update` / `.delete`.

| Extra field | Notes |
|---|---|
| `school_class_id` | Required on create, optional on amend |
| `school_class` | Present when loaded: `id`, `name`, `code` |

`name` and `code` are unique within the class, with the same moving-parent scoping as
classes, and the same refusal to file under a retired class.

A section has no meaning outside the class it splits, but a class with no students yet can
legitimately be reorganised, so moving is allowed rather than refused.

---

## 7. Permissions

Module 02 seeds 24 permissions. Routes are gated on a **permission**, not a role, so two
staff of the same role can hold different rights without inventing another role. The Super
Admin bypasses the check entirely.

| Role | Academic permissions | What it cannot do |
|---|---|---|
| `SUPER_ADMIN` | all 24 | - |
| `ADMIN` | 22 | Cannot delete a session or a term |
| `REGISTRAR` | 14 | Cannot activate, cannot delete, cannot edit the school profile |
| `STAFF` | 6 (all read) | Cannot change anything |
| `STUDENT` | 0 | - |

An administrator runs the school day to day but must not erase academic history, since a
deleted session or term that a later module has referenced is irreversible. A registrar
builds the calendar and class structure as part of admitting students, but which year the
school is in is an administrative decision, so session creation and activation are absent.

### 7.1 Permission names

| Group | Names |
|---|---|
| School | `school.view`, `school.update` |
| Sessions | `academic_sessions.view`, `.create`, `.update`, `.delete`, `.activate` |
| Terms | `terms.view`, `.create`, `.update`, `.delete`, `.activate` |
| Class levels | `class_levels.view`, `.create`, `.update`, `.delete` |
| Classes | `classes.view`, `.create`, `.update`, `.delete` |
| Sections | `sections.view`, `.create`, `.update`, `.delete` |

### 7.2 Two seeds, one database

Module 02's permissions are seeded by a **separate seeder** from Module 01's, and granted
with `syncWithoutDetaching()`. Module 01 uses `sync()`, which *replaces* a role's whole
permission set - so had these been added there, every Module 01 grant would have had to be
repeated for it to survive a re-seed. Keeping them apart means each module owns its own
permissions and neither can silently revoke the other's.

Re-seeding either one preserves the other. Both create permissions with `updateOrCreate()`
on name, so re-seeding a running installation neither fails on the unique index nor
duplicates rows.

---

## 8. Seeding

`php artisan migrate:fresh --seed` produces:

| Record | Count |
|---|---|
| School | 1 |
| Active academic session | 1 |
| Terms in it | 3, one of them active |
| Class levels | 4 |
| Classes and sections | 0 - left for the school to build |

The school seeder is **idempotent and rename-safe**. It finds the existing singleton by
`singleton_key`, not by name, so changing `SCHOOL_NAME` in `.env` and re-seeding updates the
existing row. Looking it up by name would treat the new name as a different school and try
to insert a second row, failing with an integrity error - turning one line of configuration
into a broken install.

The calendar seeder does **not** replace a calendar that already exists. Re-seeding is a
setup action, not a reset: running it against a live installation must not roll the school
back to a fresh year and lose the term a class is currently in.

---

## 9. Out of scope

Deliberately absent from this module, and not to be inferred from it:

`school_id` and multi-tenancy, branches, students, enrollment, subjects, teachers,
assessments, results, grading, promotion, attendance, timetables, fees, notifications,
announcements, file upload, exports, `sort` and `direction` parameters, and a bulk-import
endpoint.

---

## 10. Client integration checklist

1. Send `Accept: application/json` and `Content-Type: application/json` on every call.
2. Log in, store the token, send `Authorization: Bearer <token>` on everything after.
3. On boot, call `GET /academic-context` **once**. If `message` reports incomplete setup,
   show setup rather than an empty dashboard.
4. Read list responses as `body.data` - never `body.data.data`.
5. Treat a missing `data` key and a present-but-null `data` as different things.
6. Create sessions and terms, then call `activate`. Do not try to set `status` directly;
   it is not an accepted field.
7. Cap client-side `per_page` at 100; anything higher is a 422.
8. When populating a "choose a parent" picker, send `active_only=true` - not `false`.
9. Surface `errors` field messages next to their inputs; they are written to be shown.
10. Handle 401 by re-authenticating, 403 by hiding the control rather than showing an error
    the user cannot act on.
