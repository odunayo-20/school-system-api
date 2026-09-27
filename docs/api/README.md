# API reference

One document per module. Each is self-contained: conventions, resources, every endpoint,
permissions, and the client-side checklist.

| Module | Document | Covers |
|---|---|---|
| 01 | [authentication.md](authentication.md) | Login, logout, `me`, email verification, password reset, roles and permissions, the user resource |
| 02 | [school-configuration.md](school-configuration.md) | School profile, academic sessions, terms, academic context, class levels, classes, sections |

## Shared across modules

**Base URL** `/api/v1`

**Auth** `Authorization: Bearer <token>` - Laravel Sanctum personal access tokens. Stateless:
no cookies, no CSRF token, no session. Send `Accept: application/json` on every call.

**Success** `{ "data": ..., "message": "..." }` - `message` may be omitted.
**Failure** `{ "message": "...", "errors"?: { "field": ["..."] } }` - `errors` on 422 only.

**Lists** `data` is the array; `meta` and `links` are its siblings, never nested inside it.
Read `body.data`, not `body.data.data`.

**Two null shapes, deliberately different**

| Case | Body |
|---|---|
| Succeeded, nothing to return | `{ "message": "..." }` - no `data` key |
| Absence *is* the answer | `{ "data": null, "message": "..." }` |

**Status codes** 200, 201, 401, 403, 404, 405, 422, 429. A 404 is always
`{"message":"Resource not found."}`; a single-school API has nothing else to hide.

**Permissions** routes are gated on permissions rather than roles, so two users of the same
role can hold different rights. `SUPER_ADMIN` bypasses the check. Each module seeds its own
permissions in a separate seeder, so neither module can revoke the other's grants.

## Build order

A later module depends on Module 02's academic state, so deploy in this order:

1. **01** - authentication and authorization
2. **02** - school profile, academic year, class structure

The Super Admin account and the school profile are created by seeders, not by the API:
there is no `POST /auth/register` and no `POST /school`, because the school is a singleton
and accounts are provisioned by an administrator.
