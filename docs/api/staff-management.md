# Staff Management API

**Module:** 03 - Staff Management
**Base URL:** `/api/v1`
**Auth scheme:** `Authorization: Bearer <token>` (Laravel Sanctum personal access tokens)

Covers the staff establishment: listing and searching staff, creating a staff record together
with its login account, amending employment details, and recording when somebody's employment
ends.

---

## 0. Read this first: two different statuses

Every staff record carries **two** statuses, and they answer different questions.

| Field | Question it answers | Values |
|---|---|---|
| `status` | Is this person employed by the school? | `ACTIVE`, `INACTIVE`, `TERMINATED` |
| `account_status` | Can this person log in? | `ACTIVE`, `INACTIVE`, `SUSPENDED` (Module 01) |

**Deactivating a staff member does not disable their login.** This is deliberate. A
registrar recording that a teacher has left is making an employment record; cutting that
person's access off is a security action belonging to Module 01's `users.*` permissions. The
two are therefore independent, and a record can legitimately read `status: INACTIVE` with
`account_status: ACTIVE`.

To actually stop somebody logging in, use Module 01's user administration. Both statuses
appear side by side in every staff response so the mismatch is visible rather than something
you have to go and look for.

---

## 1. Conventions

The response envelope, status codes, pagination and authentication are identical to Modules 01
and 02 - see [README.md](README.md) and
[school-configuration.md](school-configuration.md) §1. In short:

- `data` is always the payload. On a list, `meta` and `links` are its **siblings**, so read
  `body.data`, never `body.data.data`.
- Send `Accept: application/json` on every call.
- A missing record is always `404 {"message":"Resource not found."}`.
- A validation failure or a refused state change is `422`.

### 1.1 There is no delete endpoint

| Method | Path | Result |
|---|---|---|
| `DELETE` | `/api/v1/staff/{id}` | `405` |

A staff record is never deleted. Employment ends through `status`, and the record and its
history are kept. Nothing references a staff record yet, so a "delete only if nothing depends
on it" guard could never fail and would misleadingly imply the operation was safe. When
subjects, attendance and results arrive, their own foreign keys become the real guard.

There is no `staff.delete` permission either: a permission for an operation that cannot be
performed has no meaning.

### 1.2 `PUT` only, not `PATCH`

The amend endpoint accepts **`PUT` only**. A `PATCH` is answered with `405` and an `Allow`
header naming the methods that do work.

This is a deliberate difference from Module 02's academic endpoints, which accept both
(`Route::match(['put', 'patch'])`). The staff amend is a whole-record write, so one verb is
one fewer thing for a client to try and mis-use.

### 1.3 `PUT` is a whole-record write

`name` and `staff_type` are **required** on amend. There is no partial form of this endpoint
on purpose: a client cannot half-update a record by omitting the name. Fetch, modify, send
the whole record back.

---

## 2. The staff resource

```json
{
  "data": {
    "id": 1,
    "staff_number": "STAFF-0007",
    "name": "Amina Yusuf",
    "email": "amina@example.test",
    "staff_type": "TEACHING",
    "designation": "Head of Science",
    "employment_date": "2024-09-01",
    "phone": "+234 800 000 0001",
    "status": "ACTIVE",
    "account_status": "ACTIVE",
    "created_at": "2026-09-27T19:54:03+00:00",
    "updated_at": "2026-09-27T19:54:03+00:00"
  }
}
```

| Field | Type | Notes |
|---|---|---|
| `id` | int | |
| `staff_number` | string? | Optional on create. Upper-cased and trimmed. Derived as `STAFF-####` from the account id when omitted |
| `name` | string | Lives on the linked account, not on `staff`. Required on create and amend |
| `email` | string | Read-only here. See §2.1 |
| `staff_type` | enum | `TEACHING` or `NON_TEACHING`. Required on create and amend |
| `designation` | string? | Free text, max 100. **Not** a catalogue - see §2.2 |
| `employment_date` | date? | `YYYY-MM-DD`. Cannot be in the future |
| `phone` | string? | Max 30. The employment contact number |
| `status` | enum | `ACTIVE`, `INACTIVE`, `TERMINATED` |
| `account_status` | enum? | The linked account's `UserStatus`. Read-only |
| `created_at` / `updated_at` | string? | ISO 8601 |

Every field is whitelisted explicitly, so adding a column to `staff` or `users` later cannot
leak it into the API by accident. No password, token, role, `email_verified_at` or
`last_login_at` is ever returned.

### 2.1 What the amend endpoint will NOT change

`PUT /api/v1/staff/{id}` does **not** accept `email`, `password`, `role` or `user_id`. Sending
them is ignored, not rejected.

| Field | Why not |
|---|---|
| `email`, `password` | Credentials. Module 01 holds them behind `users.update`, granted to `ADMIN` and `SUPER_ADMIN` only. Accepting them here would hand `REGISTRAR` - who holds `staff.update` - a way to take over any account: change the address, then take the password reset |
| `role` | The role is fixed to `STAFF` when the account is created. Grant a different role through Module 01 |
| `user_id` | The one-to-one link to the account. A staff record cannot be repointed at somebody else's login |

`name` **is** amendable - it is a display name, not a credential.

### 2.2 `designation` is free text

Any string up to 100 characters. Suggested values like `Principal`, `Vice Principal`,
`Teacher`, `Accountant`, `Registrar Clerk` are used in the seeder and the docs, but nothing
validates against a list and no `designations` table exists. A school is a single institution
with a handful of titles; a catalogue would need an admin UI and a migration every time a
title is added, to solve a problem a string does not have.

### 2.3 `staff_number`

Optional. Leave it out and the API derives `STAFF-` + the new account's id, zero-padded to
four digits: `STAFF-0007`.

Uniqueness is structural rather than calculated. The number comes from a primary key, which
cannot be read twice, so two concurrent creates cannot derive the same one. (A `count() + 1`
scheme *can*: two simultaneous creates read the same count, derive the same number, and the
loser's insert dies on the unique index as a 500.)

A value you do supply is trimmed and upper-cased **before** the uniqueness check, so
`"  sta-07  "`, `"STA-07"` and `"Sta-07"` are one number and the second one is rejected with
a 422 rather than stored twice.

---

## 3. Endpoints

All six require a bearer token, an **active** account, and the listed permission.

| Method | Path | Permission |
|---|---|---|
| `GET` | `/staff` | `staff.view` |
| `POST` | `/staff` | `staff.create` |
| `GET` | `/staff/{id}` | `staff.view` |
| `PUT` | `/staff/{id}` | `staff.update` |
| `POST` | `/staff/{id}/activate` | `staff.activate` |
| `POST` | `/staff/{id}/deactivate` | `staff.deactivate` |

### 3.1 `GET /staff`

**Query parameters** - all validated. An unknown value is a `422` naming the permitted values,
never a silently empty page.

| Parameter | Values | Notes |
|---|---|---|
| `search` | string, max 120 | Matches staff number, **and** the linked account's name and email. Case-insensitive |
| `staff_type` | `TEACHING`, `NON_TEACHING` | |
| `status` | `ACTIVE`, `INACTIVE`, `TERMINATED` | Employment status |
| `account_status` | `ACTIVE`, `INACTIVE`, `SUSPENDED` | The login account's status |
| `has_account` | `1`, `0`, `true`, `false` | See below |
| `per_page` | 1-100, default 15 | |

`search` covers name and email because a registrar looking for "who works here called Amina"
should not have to know which table a field lives in.

**Ordering is fixed**, and there is no `sort` parameter: `employment_date` descending, records
with no employment date last, then `name` ascending as a stable tiebreak. So the list reads
most-recently-appointed first, and paging cannot show the same person twice.

**`has_account` cannot currently be selective.** `staff.user_id` is `NOT NULL` and `UNIQUE` -
one staff record per login - so every staff record has an account by construction. Therefore
`has_account=true` returns everything and `has_account=false` returns nothing. It is
implemented as an honest existence check rather than faked, and the behaviour is asserted in
the test suite so the constraint stays pinned down.

```
GET /api/v1/staff?search=amina&staff_type=TEACHING&status=ACTIVE&per_page=25
```

### 3.2 `POST /staff`

Creates a staff record **and** the login account behind it, in one transaction.

`staff.user_id` is `NOT NULL`, so an employment record with no account is not representable -
this is a property of Module 01's schema, not a choice made here. Rather than create the
account silently, **`email` and `password` are required inputs**. The endpoint states that it
creates an account, and you supply the credentials.

**Request**

| Field | Required | Rules |
|---|---|---|
| `name` | yes | string, max 255 |
| `email` | yes | valid email, max 255, unique. Lower-cased before the check |
| `password` | yes | `confirmed`, and the shared password policy: min 8 with upper case, lower case, a number and a symbol |
| `staff_type` | yes | `TEACHING` or `NON_TEACHING` |
| `staff_number` | no | max 50, unique. Derived when omitted |
| `employment_date` | no | date, not in the future |
| `phone` | no | max 30 |
| `designation` | no | max 100 |

There is **no `role` field** and **no `status` field**. The role is set to `STAFF` in code, and
the absence of the key is what enforces it - no payload can ask for `SUPER_ADMIN`. A new staff
member is `ACTIVE`; creating one already inactive would only produce a record that needs a
second call before it could be used.

**`201`**

```json
{
  "data": { "id": 1, "staff_number": "STAFF-0007", "status": "ACTIVE", "...": "..." },
  "message": "Staff member created with a login account. The account is active and the password is the one supplied."
}
```

The account is created **already verified**: an administrator is provisioning a colleague's
login, exactly as the Super Admin seeder does, and requiring this account to click a
verification link nobody is watching for would only produce locked-out staff.

The password is never echoed.

### 3.3 `GET /staff/{id}`

`200` with one record, or `404 {"message":"Resource not found."}`.

### 3.4 `PUT /staff/{id}`

A whole-record write: `name` and `staff_type` are required (§1.3). See §2.1 for what it will
not change.

| Field | Required | Rules |
|---|---|---|
| `name` | yes | max 255 |
| `staff_type` | yes | `TEACHING` or `NON_TEACHING` |
| `status` | no | `ACTIVE`, `INACTIVE`, `TERMINATED`. Reaches `TERMINATED`, which has no dedicated endpoint |
| `staff_number` | no | max 50, unique, ignoring this record's own current value |
| `employment_date` | no | date, not in the future |
| `phone` | no | max 30 |
| `designation` | no | max 100 |

`status` goes through the same code as `activate` and `deactivate`, so there is one
implementation of what a transition means. The dedicated endpoints exist for the two common
transitions and are separately permission-gated; `PUT` covers the general case, the same
pattern Module 02 uses for catalogue records that are neither singletons nor derived.

**A terminated record cannot be amended at all** - `422`. The record and its history are
kept, but the fact that it ended does not change.

### 3.5 `POST /staff/{id}/activate`

Sets `status` to `ACTIVE`. Requires `staff.activate`.

Activating somebody who is already active is a **200 no-op**, not a conflict: a double-clicked
button should not be told the request was dangerous.

Refused with `422` if the employment is `TERMINATED`.

### 3.6 `POST /staff/{id}/deactivate`

Sets `status` to `INACTIVE`. Requires `staff.deactivate`. Touches nothing else - not
`users.status`, not tokens, not `email_verified_at`.

Deactivating somebody already inactive is a **200 no-op**. Refused with `422` if the
employment is `TERMINATED`.

---

## 4. Employment status

| Value | Meaning |
|---|---|
| `ACTIVE` | Currently employed |
| `INACTIVE` | Employed but not currently working: sick leave, secondment. Reversible |
| `TERMINATED` | Employment has ended permanently. **Terminal** |

`TERMINATED` is refused on any change to a non-`TERMINATED` value, and the record can no longer
be amended. Employment that has ended does not un-happen, and a status that could be reversed
would put a leaver back on a current staff list after they had gone - which is the opposite of
what a permanent record of departure is for.

A staff record is the only place `TERMINATED` is reachable, through `PUT`. It has no dedicated
endpoint because it is a rare, one-way event rather than a routine transition.

---

## 5. Permissions

| Permission | Grants |
|---|---|
| `staff.view` | `GET /staff`, `GET /staff/{id}` |
| `staff.create` | `POST /staff` |
| `staff.update` | `PUT /staff/{id}` |
| `staff.activate` | `POST /staff/{id}/activate` |
| `staff.deactivate` | `POST /staff/{id}/deactivate` |

There is no `staff.delete` (§1.1).

### 5.1 Role grants

| Role | view | create | update | activate | deactivate |
|---|:--:|:--:|:--:|:--:|:--:|
| `SUPER_ADMIN` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `ADMIN` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `REGISTRAR` | ✓ | ✓ | ✓ | | |
| `STAFF` | | | | | |
| `STUDENT` | | | | | |

**Registrar does not get activate or deactivate.** They build the staff establishment as part
of admitting and placing people, so reading, creating and amending is theirs.

Ending or resuming an employment is a supervisory decision about a person rather than a data
edit, and it is the one most expensive to get wrong and hardest to notice, so it is held at
`ADMIN`. Note that this is *not* a security boundary: deactivation leaves the login untouched
(§0), so a registrar holding it would not be cutting anybody's access. If your school disagrees,
it is one line in `StaffPermissionSeeder`.

**Staff get nothing.** A staff member manages nobody. Their own profile is Module 01's
`profile.*`.

### 5.2 Per-user grants

Routes are gated on permissions, not roles, so a single staff member can be given `staff.view`
without a new role:

```
POST /api/v1/staff/{id}/anything   ->  staff.view  ->  403
```

Granting `staff.view` to one person does not let them create, amend or deactivate.

---

## 6. Client checklist

1. `POST /auth/login` and keep the `data.token`.
2. Send `Authorization: Bearer <token>` and `Accept: application/json`.
3. `GET /staff` to list. Read `body.data`, not `body.data.data`.
4. To hire somebody: `POST /staff` with `name`, `email`, `password` + `password_confirmation`,
   `staff_type`. **This creates their login** - tell them to change the password.
5. To change a job title, phone, number or type: `GET /staff/{id}`, modify, `PUT` the whole
   record back with `name` and `staff_type` included.
6. To record a departure: `POST /staff/{id}/deactivate` (temporary) or
   `PUT` with `status: TERMINATED` (permanent). **Neither disables their login** - use Module
   01 for that.
7. Do not send `email`, `password`, `role` or `user_id` on an amend. They are ignored, and
   `email` and `password` belong to Module 01.
8. There is no delete. Do not call `DELETE`.
9. Use `PUT`, not `PATCH`.
10. Handle `422` by reading `errors` - a bad filter value names the permitted values.
