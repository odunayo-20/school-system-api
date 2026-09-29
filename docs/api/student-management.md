# Student Management API

The pupil roll: who is on it, and who has left it.

Module 04. Four endpoints, one table, and a set of refusals that matter more than the
endpoints do.

- [0. Read this first: a pupil is not an account](#0-read-this-first-a-pupil-is-not-an-account)
- [1. Conventions](#1-conventions)
- [2. The student resource](#2-the-student-resource)
- [3. Endpoints](#3-endpoints)
- [4. Roll status](#4-roll-status)
- [5. Permissions](#5-permissions)
- [6. Client checklist](#6-client-checklist)

---

## 0. Read this first: a pupil is not an account

`POST /students` does **not** create a login. There is no `email`, no `password` and no
`role` in its payload, and the pupil it returns has `account_status: null`.

This is the single most important thing to know about this module, and it is the opposite of
Module 03:

| | `POST /staff` | `POST /students` |
|---|---|---|
| Creates a login? | **Yes** | **No** |
| `email` / `password` required? | Yes | Not accepted |
| `user_id` | `NOT NULL` (Module 01 constraint) | `NULL`, unique |
| `account_status` in the response | always a value | `null` until an account is linked |
| Name lives on | `users.name` | the pupil's own `first_name` etc. |

### 0.1 Why the asymmetry

`staff.user_id` is `NOT NULL`. That constraint predates Module 03 — it comes from Module 01 —
and Module 03 inherited rather than chose it, so a staff record cannot exist without an
account and credentials have to be part of the request.

**No such constraint exists for pupils, and requiring one would be wrong:**

- A Nursery entrant has no email address.
- A child's login should not be a precondition of recording that they exist.
- The lifecycle runs **Student → Admission → Enrollment**. Identity comes first; anything that
  wants a portal account comes later.

The honest consequence: **this API can create a pupil that nobody can log in as.** That is the
intended state, not a bug. Provisioning the portal account belongs to a future portal module,
which will own that path. `account_status` tells you whether it has happened yet.

### 0.2 The account is reserved, not exposed

`students.user_id` exists, is `nullable` and is `unique`, and `User::student()` is defined —
but **no Module 04 payload can set it**, on create or on amend.

The absence of the key is the protection. A validation rule that rejected `user_id` would be
a deny-list somebody has to remember to extend; there is no key to send. This matters because
Module 04 grants `students.update` to `REGISTRAR`, and registrars are not administrators.
Accepting `user_id` there would let a registrar attach a child's record to *any* login in the
system — including an administrator's — and read the roll with that account's permissions.
That is an account takeover wearing a field that looks like harmless record-keeping.

### 0.3 The name lives on the pupil

Module 03 kept one copy of a staff member's name on `users.name`, because the account was
mandatory. A pupil can exist with no account, so the name has to belong to the pupil:

```json
{ "first_name": "Ada", "middle_name": "Ngozi", "last_name": "Okonkwo" }
```

A linked account's `users.name` is a login-facing label. It is **not** this pupil's identity,
it is not read for display here, and it is not kept in step with these fields.

This also has a practical payoff: searching the roll touches **one table**. Module 03's staff
search has to reach through to `users` for the name and email, and carries a comment about it.
Here the columns are simply there.

---

## 1. Conventions

Inherited from the [API reference](README.md) and unchanged: bearer tokens, `Accept:
application/json`, the `{ "data": ..., "message": ... }` envelope, `meta`/`links` as siblings
of `data`, and 200/201/401/403/404/405/422/429.

### 1.1 There is no delete endpoint

`DELETE /students/{id}` is not registered. A `DELETE` gets **405** with
`Allow: GET, HEAD, PUT`.

A pupil is a child and a person, and the school does not delete them. They leave the roll by
being marked `WITHDRAWN` or `GRADUATED`, and the record stays: a graduated pupil is still a
graduate, a withdrawal is a fact about a date.

Module 03 left staff deletion out on narrower grounds — nothing referenced a staff record yet,
so a dependents guard would have been a check that could never fail. **The reasoning here
holds no matter what arrives later.** When enrollment, attendance and result tables come, their
own `restrictOnDelete` keys become a second line of defence, but they are not the reason this
endpoint is absent.

A record that cannot be amended is still **readable** — see §4.

### 1.2 `PUT` only, not `PATCH`

`PATCH /students/{id}` gets **405** with `Allow: GET, HEAD, PUT`. A `PUT` that silently
behaved like a partial write would be worse than a clear refusal.

### 1.3 `PUT` is a whole-record write

`first_name` is **required** on amend. Omitting it is a 422, so a client cannot half-update a
pupil by sending only the field it wants to change.

`middle_name`, `last_name`, `date_of_birth`, `gender` and `status` are all optional. An amend
that omits `status` leaves the roll status alone.

### 1.4 No `activate` / `deactivate` endpoints

Unlike Module 03, there is no `POST /students/{id}/activate`. A pupil's lifecycle is one
orthogonal question and it is amended through the same `PUT` as the name.

Module 03 needed dedicated endpoints because **ending somebody's employment is a supervisory
decision** that had to be granted separately from editing a staff record. None of that applies
to a child's roll status: recording that a child moved away is a registrar's ordinary
record-keeping. The terminal rule in §4, not a withheld permission, is what stops the status
being abused. Two extra routes and two extra permissions to fix a typo in a surname is a bad
trade.

### 1.5 No academic filters

`GET /students` cannot filter by class, section, session or term. A pupil's placement is an
**enrollment** fact, and the `students` table has no column for it — see §2.2.

The endpoint that will answer "who is in JSS 2 this session" is
`GET /classes/{class}/students`, and it will be honest about academic sessions in a way a
filter on the roll never can be.

---

## 2. The student resource

```json
{
  "data": {
    "id": 7,
    "student_number": "STU-0007",
    "first_name": "Ada",
    "middle_name": "Ngozi",
    "last_name": "Okonkwo",
    "full_name": "Ada Ngozi Okonkwo",
    "date_of_birth": "2015-04-02",
    "gender": "FEMALE",
    "status": "ACTIVE",
    "account_status": null,
    "created_at": "2026-09-27T09:14:00+00:00",
    "updated_at": "2026-09-27T09:14:00+00:00"
  },
  "message": "..."
}
```

Every field is listed explicitly. Nothing is exposed by omission, so adding a column later
cannot leak it by accident.

### 2.1 `status` vs `account_status`

Two different questions, reported side by side and never conflated — the lesson Module 03
had to learn with employment status versus account status:

| Field | Question | Values |
|---|---|---|
| `status` | Is this child on the roll? | `ACTIVE`, `INACTIVE`, `GRADUATED`, `WITHDRAWN` |
| `account_status` | Can this login be used? | `ACTIVE`, `INACTIVE`, `SUSPENDED`, or `null` |

A child can be `ACTIVE` on the roll with a `SUSPENDED` login, or `WITHDRAWN` from the roll
with a still-working login. **Changing the roll status never touches the account** — see the
test "does not touch a portal account when a pupil is withdrawn".

`account_status` is always present, even when `null`. One field answers both questions, and
its absence should never be a schema change waiting to break a client.

### 2.2 What is deliberately absent from the resource and the table

No `class_id`, `current_class_id`, `section_id`, `session_id`, `admission_number`, `email`,
`password`, `role_id` or `name`.

The academic ones are the interesting omission. A pupil's placement is a decision about a
*specific academic session*, and it belongs in a table that holds a history of those decisions:

```
2024/2025 → JSS 2 → A
2025/2026 → JSS 3 → A
2026/2027 → SS 1  → B
```

Those are three rows in a future `enrollments` table and **one** row here. A
`current_class_id` on `students` would be a denormalised copy of the newest of those three,
and a copy is only as correct as the code maintaining it: the first promotion that wrote the
column but failed to write the enrollment row would leave the roll showing a child in a class
they had left, with nothing in the database able to say so.

`admission_number` is absent for a different reason: it identifies one *admission attempt*, and
a child can have more than one. Admission numbering belongs to the admission module.

A pupil's **email is not exposed**, unlike a staff member's. A staff member's email is their
work identity and one way a registrar finds them. A child's login address is none of those
things: it is a minor's personal data, it is not needed to identify them on a roll, and
reading other people's accounts is Module 01's `users.view` business.

### 2.3 `gender`

`MALE`, `FEMALE`, or `null`.

**A stated limitation, not a considered policy:** this pair cannot represent a pupil who is
neither, and the only ways to record such a child today are an inaccurate value or no value at
all. It is the smallest model that records what the overwhelming majority of pupils are, and
it is what the enum can honestly offer.

`null` is a first-class answer, not a failure to collect: a school cannot always know, and a
record that had to guess would put a fabricated value on a child's permanent file.

Widening is deliberately **not** done pre-emptively. A value with no case behind it cannot be
filtered, and a filter over a list the enum does not define would be a guess. When it is
needed, the change is additive and contained: add a case to the enum, widen the column. Every
reader goes through `values()` and validation uses `Rule::enum()`, so nothing else changes.

### 2.4 `student_number`

Optional. Nullable, unique, up to 50 characters, normalised to trimmed upper-case.

- **Client supplies one** → it is honoured, normalised, and checked for uniqueness.
- **Client omits it** → a number is derived from the record's own primary key: `STU-0007`.

The derived form is `STU-` + the id, zero-padded to 4 digits. Two reasons for deriving from
the primary key rather than `count() + 1`:

1. **Concurrency.** `count() + 1` is wrong under simultaneous creates: both read the same
   count, derive the same number, and the loser's insert dies on the unique index as a **500**.
   A primary key cannot be read twice, so the *derived* value cannot collide.
2. **Cost.** No table scan, no `max()` query, no lock.

**You will never see the intermediate value.** The derived number depends on the id the insert
produces, so the row is written with a one-off reservation and rewritten inside the same
transaction before the response is built. A client cannot submit, receive or trigger it: a
pupil added without a number is always returned with the derived one, and if the second write
fails the pupil is rolled back rather than left holding a temporary number.

Normalisation matters here: `" sta-01 "`, `"STA-01"` and `"Sta-01"` are one number. Without
folding them, the unique index would admit duplicates and the roll would show the same child
twice.

Admission numbering is a **different** number and is not this field.

---

## 3. Endpoints

| Method | Path | Permission |
|---|---|---|
| `GET` | `/students` | `students.view` |
| `POST` | `/students` | `students.create` |
| `GET` | `/students/{id}` | `students.view` |
| `PUT` | `/students/{id}` | `students.update` |

All four require an active bearer token (`auth:api` + `active`).

### 3.1 `GET /students`

**Query parameters**

| Parameter | Values | Notes |
|---|---|---|
| `search` | free text | Matches student number, first, middle and last name |
| `status` | `ACTIVE`, `INACTIVE`, `GRADUATED`, `WITHDRAWN` | |
| `gender` | `MALE`, `FEMALE` | |
| `per_page` | 1–100, default 15 | |
| `page` | integer | |

```bash
curl -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8000/api/v1/students?search=okonkwo&status=ACTIVE"
```

**Ordering: by surname, then first name, then id.**

A roll is read alphabetically, the way a school reads it — not newest-first, the way an audit
log would be. Surname first is also how a registrar's own handwriting sorts it.

Two details that are easy to get wrong:

- **A pupil with no surname sorts last**, not at the top of the alphabet. An empty string sorts
  before every letter, so a missing surname would otherwise read as though it were the first
  name on the roll. Expressed as a `CASE` rather than `NULLS LAST` so it is the same query on
  all four supported drivers.
- **`id` is the final tiebreak.** Two children who share a name must not be able to swap places
  between one page and the next; without it, paging through a roll with duplicate names can show
  the same pupil twice and skip another.

**Search is escaped.** A `%` or `_` typed into `search` is matched literally. Unescaped, a
search for `%` becomes `%%%` and returns the entire roll — which would turn a box meant to
narrow the list into a way to dump it.

Filters are preserved in pagination links, so a next page stays filtered.

**Deliberately not offered:** `has_account`. Module 03's equivalent was honest but useless —
every staff member necessarily has an account, so it returned everything or nothing. Here
`user_id` is nullable, so the same filter *would* return a real subset: "which pupils have no
portal login yet" is a genuine question, and one the registrar will ask precisely because this
module creates pupils without accounts. It is left out only because it was not in the approved
scope for this module, not because it would not work. It is a one-line addition.

Also absent: `school_id` and `owner_user_id` (Module 01 filters *accounts*; this filters
*people*), and every academic filter (§1.5).

### 3.2 `POST /students`

```bash
curl -X POST -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"first_name":"Ada","last_name":"Okonkwo","date_of_birth":"2015-04-02","gender":"FEMALE"}' \
  http://localhost:8000/api/v1/students
```

**201**, and the message says what happened:

> Student added to the roll. No login account was created.

**Accepted fields**

| Field | Required | Rules |
|---|---|---|
| `first_name` | **yes** | string, max 100 |
| `middle_name` | no | string, max 100 |
| `last_name` | no | string, max 100 |
| `date_of_birth` | no | date, not in the future |
| `gender` | no | `MALE` \| `FEMALE` |
| `student_number` | no | max 50, unique |

**Not accepted, and not merely ignored at the validation layer:** `email`, `password`, `role`,
`role_id`, `user_id`, `current_class_id`, `admission_number`.

Only `first_name` is required, because it is the one name the school will actually call the
child by. A pupil with a single name is a real case, and **inventing a surname to satisfy a
`NOT NULL` column would put fabricated data on a child's permanent record** — the opposite of
what a required field is supposed to guarantee.

**`status` is not accepted.** A new pupil is `ACTIVE`: a child is put on the roll to be
taught, and a record born `INACTIVE` or `WITHDRAWN` would need a second call before it meant
anything. Sending `status` is **silently ignored**, not rejected — a client that sends a field
the endpoint does not define should not be told the whole request failed, since the fields that
*were* defined were applied correctly. (Same convention as Module 03's `staff.status`.)

### 3.3 `GET /students/{id}`

200 with the student resource. 404 for a pupil who does not exist.

### 3.4 `PUT /students/{id}`

Whole-record write. **`first_name` is required** (§1.3).

**Accepted:** `first_name` (required), `middle_name`, `last_name`, `date_of_birth`, `gender`,
`student_number`, `status`.

**Not accepted:** `user_id` and every account field — see §0.2 for why accepting `user_id`
here would be a privilege escalation.

```bash
curl -X PUT -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"first_name":"Ada","last_name":"Nwosu","status":"INACTIVE"}' \
  http://localhost:8000/api/v1/students/7
```

200. A **departed pupil is refused** — see §4.

---

## 4. Roll status

| Value | Meaning | Reversible |
|---|---|---|
| `ACTIVE` | On the roll, being taught | — |
| `INACTIVE` | On the roll, not being taught right now: a transfer out of area, a long absence | **Yes** |
| `GRADUATED` | Finished the school | **No** |
| `WITHDRAWN` | Left before finishing | **No** |

Two terminal states rather than one, because "this child left" and "this child completed the
school" are different facts and collapsing them into a single `LEFT` would lose the
distinction the school most wants to keep.

There is no `PENDING`: a pupil is not an application. An application is an **admission**, and
an admission has its own lifecycle in a future module.

### 4.1 A departed pupil's record is read only in full

`GRADUATED` or `WITHDRAWN` freezes the **entire** record. Any amend gets **422**:

> This pupil has already left the school, so the record can no longer be amended.

This is stricter than "the status is the protected part", and it is Module 03's rule exactly —
a terminated employment cannot be amended either. A graduation is a fact about a date; a later
amend must not be able to rewrite what was recorded about a child afterwards, **and must not be
able to quietly reinstate them** so they reappear on a current roll.

**This is reported as a 422 with a message, not a field-level validation error.** The payload
is well formed and permitted — it is the pupil's current state that makes it impossible.
Attaching a field key would imply the client should change a value in the request, which would
be wrong advice.

**A retry is refused too.** A client that times out and resends the exact request that recorded
the withdrawal gets the same 422. An earlier draft of this module allowed a no-op retry, on the
argument that retrying a `PUT` should always be safe; but `update()` refuses every amend on a
terminal record, so that branch was unreachable. Refusing outright is the more honest answer:
the record is **closed**, and the message says so rather than leaving the client to guess which
amends are still permitted. Retry idempotence still holds for pupils who have **not** left,
which is where it matters.

### 4.2 Read-only, not hidden

A departed pupil is still fully **readable**, and still appears in `GET /students` and in
`?status=GRADUATED`. A registrar looking up a former pupil is the most ordinary thing in the
world, and the record surviving is exactly why departure is a status rather than a deletion.

---

## 5. Permissions

| Permission | Grants |
|---|---|
| `students.view` | `GET /students`, `GET /students/{id}` |
| `students.create` | `POST /students` |
| `students.update` | `PUT /students/{id}` |

There are deliberately only three.

**No `students.delete`.** No delete endpoint is registered, so a permission for it would be a
grant with no meaning — the same reasoning that left `staff.delete` out (§1.1).

**No `students.status`.** Module 03 separated lifecycle from profile editing because *ending
somebody's employment is a supervisory decision*. A child's roll status is not (§1.4), so the
split would buy nothing here. Splitting it later is additive: move the transition out of the
amend, add the permission, move the grant.

**The names are plural** — `students.*`, not `student.*`. This follows the majority convention
in this project (`school.view`, `academic_sessions.*`, `class_levels.*`, `classes.*`,
`sections.*`, `terms.*`) and matches the `students.view` that Module 01 already used as its
worked example. Module 03's `staff.*` is the outlier; it is left alone, because renaming a
seeded permission in a completed module is a data migration and a breaking change to a live
API for the sake of a consistency that is already 90% of the way there.

### 5.1 Role grants

| Role | `view` | `create` | `update` |
|---|:--:|:--:|:--:|
| `SUPER_ADMIN` | ✓ | ✓ | ✓ |
| `ADMIN` | ✓ | ✓ | ✓ |
| `REGISTRAR` | ✓ | ✓ | ✓ |
| `STAFF` | — | — | — |
| `STUDENT` | — | — | — |

**`REGISTRAR` holds all three.** RoleSeeder's own description of the role is "Handles
admissions, enrollment and student records" — the roll is the core of it. Withdrawing a pupil
is a record-keeping fact about the roll, not a supervisory decision (§1.4), and the terminal
rule — not a withheld permission — is what keeps the status honest.

**`STAFF` holds nothing.** A teacher needs to know which class a pupil is in, and that is a
question about an **enrollment** — a future module's answer — not a licence to read and
rewrite the whole roll.

**`STUDENT` holds nothing over the roll.** A pupil's own record is reached through their own
account; the whole roll is not theirs to browse. Module 01's `profile.*` pair stays the only
thing a pupil holds, and it still works — verified live, a pupil account gets 403 on
`/students` and 200 on `/auth/me`.

### 5.2 Per-user grants

Same as every module: grant `students.view` to one user without changing their role.

---

## 6. Client checklist

- [ ] Send `Accept: application/json` and a bearer token on every call.
- [ ] **Do not send `email` or `password` to `POST /students`.** It creates no account.
- [ ] Do not expect `POST /students` to return a usable login. `account_status` will be `null`.
- [ ] Expect `account_status: null` — the field is always present, even when absent.
- [ ] Treat `status` and `account_status` as two different questions.
- [ ] Send `first_name` on **every** `PUT`; it is a whole-record write.
- [ ] Omit `student_number` unless your school has its own numbering scheme; one is derived.
- [ ] Do not try to `DELETE` a pupil. Use `status: WITHDRAWN` or `GRADUATED`.
- [ ] Do not try to `PATCH`. Use `PUT`; `PATCH` is 405.
- [ ] Do not send `status` on create — it is ignored and the pupil is created `ACTIVE`.
- [ ] Expect 422 (not 400/409) when amending a pupil who has left.
- [ ] Render `last_name` and `gender` as optional; both are `null` for many pupils.
- [ ] Do not look for a class or session on a pupil. It is not there, by design (§2.2).
