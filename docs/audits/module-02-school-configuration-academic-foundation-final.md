# Module 02 - School Configuration & Academic Foundation: Final Report

Companion to [module-02-school-configuration-academic-foundation-audit.md](module-02-school-configuration-academic-foundation-audit.md)
(the design audit that preceded implementation) and
[../api/school-configuration.md](../api/school-configuration.md) (the endpoint reference).

---

## 0. Status

**Complete and verified.**

| Check | Result |
|---|---|
| Test suite | 275 passed, 1076 assertions, 0 failures |
| Pint | clean |
| `migrate:fresh --seed` | succeeds |
| Route table | 37 routes (7 Module 01 + 30 Module 02) |
| Live HTTP smoke | performed against `artisan serve` |
| Module 01 regression | none; its tests pass unchanged except where noted in 4.1 |

---

## 1. What was built

### 1.1 Stack decisions

| Decision | Why |
|---|---|
| Single school, no `school_id` | The requirement is one school. A tenancy column on six tables would be an unused abstraction that later invites a half-built multi-tenant system. See audit C.10. |
| `schools.singleton_key` unique index | Makes "exactly one school" a database fact, not a convention. `School::current()` returns the only row regardless of status. |
| Nullable `active_marker` + unique index | Many non-active rows, one active row, enforced by the database. A plain `status` string cannot express this. |
| Derived session/term status | Removes an entire class of contradiction (see 3.1). |
| `schoolId`-free academic context | The current year and term have one answer in the system, served by one endpoint. |
| Permissions, not roles, on routes | Two users of the same role can hold different rights without inventing another role. |
| One `AcademicStructureService` for three catalogues | The three are one nested hierarchy with shared rules; three services would repeat the shape three times and drift. See audit D.6. |

### 1.2 Deliverables

- 6 migrations, 4 enums, 6 models, 1 shared `TracksSingleActiveRecord` concern
- 6 factories, 4 seeders
- 3 services, 11 form requests + 1 shared abstract list request, 2 shared validation traits
- 7 controllers, 6 API resources
- 30 routes
- 12 test files, 181 tests
- `config/school.php` and 13 config keys
- 3 documents

---

## 2. How the audit's findings were closed

Every item in the audit is implemented as specified. Four items were **deliberately
changed** during implementation, each with a reason:

| Audit item | Delivered | Note |
|---|---|---|
| D.11: `per_page` capped at 100 | **as specified** | Was not implemented in the first pass; found and fixed during verification. See 4.2. |
| D.11: allow-listed `sort` / `direction` | **not implemented** | Each endpoint has one sensible order chosen in the service. A caller-chosen column is an injection surface for no benefit at this size. Recorded here as a conscious deviation, not an omission. |
| D.5: no generic key/value `settings` table | as specified | `config/school.php` plus the `schools` row covers it. |
| D.x: session name normalisation | **widened** | The audit specified folding `-` to `/`. Implementation also strips whitespace around the separator, because `2026 / 2027` is a different string from `2026/2027` and would slip past the uniqueness check. |

---

## 3. Design decisions worth recording

### 3.1 Session and term status is derived, not supplied

Neither `status` nor `active_marker` appears in any create or update payload. The only route
to `ACTIVE` is a dedicated `activate` endpoint.

Making a session current necessarily completes the one before it. Had a client been able to
post `status: "ACTIVE"` directly, it could produce a second active session, and the
database would answer with an integrity error the API renders as a **500** - a rule
violation reported as a server fault, for something the client had no way of knowing was
wrong. Removing the field removes the failure class.

`activate` is a `POST`, not a `PATCH`, because it is a state transition with a side effect
beyond the record it names, and it carries its own permission (`academic_sessions.activate`)
independently of the ability to rename a session.

### 3.2 Normalisation happens before validation, never after

Session names and catalog codes are uniquely indexed. If the model folded the value
*after* the uniqueness check, a second session named `2026/2027` would pass validation and
then collide on the unique index, surfacing a client mistake as a 500.

Both are normalised in `prepareForValidation()`, so the value that is checked and the value
that is stored are the same string. Pinned by
`ClassLevelTest > a class level code is normalised before uniqueness is checked`.

### 3.3 Delete guards where the database would also refuse

`deleteClassLevel` and `deleteClass` check for children before deleting, even though the
`restrictOnDelete` foreign key would refuse anyway.

The check turns an integrity error into a message that names the problem and can report how
many children are in the way. These three tables are the target of a foreign key from every
later module, and the difference between "still has 3 classes" and a 500 is the difference
between an actionable error and a support ticket.

### 3.4 The structure rules hold at the service, not just the edge

A class cannot be created in, or moved into, a retired class level; a section cannot be
filed under a retired class.

The requests enforce this with an `exists()` rule so a caller gets a field error. The
service enforces it again, because a rule enforced only at the edge stops being true the
first time something reaches past the edge - a command, an import, a later module.

The check is on *assignment*, not on current state: amending the name of a class whose
level has since been archived is still allowed. Archiving a level does not retroactively
make everything under it immutable, and it does not empty the level. Both halves are pinned
in `StructureServiceRulesTest`.

### 3.5 Academic context is not cached

The audit proposed memoising the context per request with the write services invalidating
it. That was dropped. A request is served by one controller instance, the result is used
once, and it costs three indexed lookups on tables holding a handful of rows. Caching would
buy nothing measurable and would introduce a class of bug in which a read after a write in
the same request returns the pre-write value, because something failed to invalidate.

### 3.6 `PUT /school` requires the whole record

`name` and `short_name` are both required on every call, and `PATCH` is not accepted (405).
This is a whole-record update, not a partial one. Omitting a required field is a 422 rather
than a silent clear, and "clear the motto" is expressed explicitly as `null`.

There is no `POST /school`: the seeder creates the record, because the school is a singleton
with nothing to create at a URL of its own.

### 3.7 Pagination keeps metadata out of `data`

Laravel's default paginated resource nests items one level deeper than every other response
in this API (`data.data`). `ApiResponse::paginated()` keeps `data` as the list and puts
`links`/`meta` beside it, so one code path reads every endpoint.

The shape is built from `$collection->resource` rather than taken from
`ResourceCollection::resolve()`, which returns a bare item list and leaves the paginator to
`PaginatedResourceResponse` to describe. Items are still resolved through Eloquent, so each
is rendered by its own API Resource.

### 3.8 Missing `data` and present-but-null `data` are different

`success(null)` omits `data` - right for an operation that simply succeeded and has nothing
to return (a delete, a logout). `nullData()` sends `"data": null` - right where the absence of
a resource *is* the answer. A client reading `body.data` finds the key missing in one case
and present-and-null in the other, and those are not the same thing to handle.

---

## 4. Defects found during verification and fixed

These were not in the audit. All were found by running the code, not by reading it.

### 4.1 `active_only=false` filtered the list - **fixed**

The worst of the four, because it was silent.

Query parameters arrive as strings, and `Builder::when()` branches on plain truthiness. The
string `"false"` is truthy, so `?active_only=false` returned exactly what
`?active_only=true` returned. The endpoint's one way of asking for the full list looked
like a way of asking for nothing, and nothing errored.

Fixed by validating the query string in `AcademicListRequest` and casting with
`$this->boolean()`, which turns `"false"` into a real `false`.

It nearly escaped: the first probe sent `active_only=0`, which is genuinely falsy and
behaved correctly. Only noticing that `"false"` had not actually been tested found it.

### 4.2 `per_page` was unbounded - **fixed**

`$request->only(['per_page'])` fed straight into `paginate()`. `?per_page=1000000` asked
the database for the entire table and forced the server to build it in memory. The audit
specified a cap of 100; it had not been implemented. Now `integer|min:1|max:100`.

### 4.3 A non-numeric filter id returned 500 - **fixed**

`?class_level_id=abc` reached a closure typed `int $levelId` and raised a `TypeError`, so a
malformed query string answered **500 Server error.** Now a 422 naming the field.

A client typo, a scanner probing a URL, and a genuine server fault all produced the same
status code, which is exactly the situation status codes exist to prevent.

### 4.4 An invalid `status` filter returned an empty page - **fixed**

`?status=BOGUS` matched nothing and answered `200` with `total: 0`. To a client that reads
as "this school has no sessions" rather than "you mistyped the filter" - and it looks
identical to a genuine empty result. Now a 422 that names the permitted values.

### 4.5 The seeder would break on a renamed school - **fixed**

`SchoolSeeder` looked the existing row up by `config('school.name')`. Changing `SCHOOL_NAME`
in `.env` and re-seeding would treat the new name as a different school and try to insert a
second row, failing on the unique index. One line of configuration became a broken install.

Fixed by finding the singleton by `singleton_key` instead. `SeederTest` pins the rename, the
idempotency, and that the id survives the rename.

### 4.6 The seeder stored untrimmed config values - **fixed**

API input is trimmed by `TrimStrings`, but a seeder writes config values straight to the
model and bypasses the middleware. A stray space in `.env` became part of the school's name
and would appear in a printed report header. An absent value also became `""`, which reads
back as a value the school has.

The seeder now trims and maps empty to `null`.

### 4.7 The school mutators rejected `null` - **fixed**

`School::shortName()` and `School::website()` folded their input unconditionally, so
`website = null` threw. Seeding an installation with no website configured failed at the
last step of `migrate:fresh --seed`.

### 4.8 `ValidatesCatalogRecord` was typed to an interface it does not implement - **fixed**

The closure was typed `Illuminate\Validation\Rule`, but `Rule::unique()` builds an
`Illuminate\Validation\Rules\Unique`, which does not implement that interface. Every create
in the academic structure was a `TypeError`. The return type is now the concrete class.

### 4.9 Module 01's email normalisation was silently broken - **fixed**

`app/Models/User.php` imported `Illuminate\Database\Eloquent\Casts\Attribute` instead of
`Illuminate\Database\Eloquent\Attributes\Attribute`. Every login normalised the email
against a class that does not exist. Found while building the authorization matrix, which
needed a correctly normalised login to work at all.

### 4.10 `asUser()` in `Pest.php` did not switch users - **fixed**

It set the `Authorization` header but never forgot the guard's already-resolved user, so in
a multi-user test the second `asUser()` call was still authorised as the first. Every
multi-user authorization test was therefore asserting nothing. It now clears stale bearer
headers and forgets the resolved guard before and after login.

This one matters beyond its own file: it is the helper every authorization test in the
project uses, so it was quietly weakening Module 01's coverage too.

### 4.11 A multi-line `tinker --execute` was mis-parsed by PowerShell - **not a code defect**

Recorded only because it cost time and the next person will hit it. `foreach (...) as $r`
loses the `$` in a PowerShell argument, and the result is a `Psy` parse error that looks
like an application fault. Use a temporary script, and remember the shell will eat `$` in
double quotes.

---

## 5. Test coverage

275 tests, 1076 assertions, 46s.

| File | Tests | Covers |
|---|---|---|
| `TermTest` | 26 | Creation, date containment, activation across sessions, delete guards |
| `AcademicSessionTest` | 21 | Lifecycle, name normalisation, activation, delete guards |
| `SchoolClassTest` | 21 | Parent scoping, uniqueness, moving, counts, delete guards |
| `ClassLevelTest` | 19 | Normalisation, uniqueness, status, counts, delete guards |
| `SectionTest` | 18 | Parent scoping, uniqueness, moving, delete |
| `DatabaseInvariantTest` | 16 | Raw SQL: singleton, uniqueness, active markers, foreign keys |
| `ListFilterTest` | 15 | Every regression in 4.1-4.4, plus accepted filters |
| `SchoolProfileTest` | 12 | Required fields, nullable handling, normalisation, singleton |
| `AcademicContextTest` | 11 | Partial configuration, independence of the three parts |
| `StructureServiceRulesTest` | 8 | 3.4 - service-level parent rules both ways |
| `SeederTest` | 7 | Idempotency, rename, normalisation, calendar preservation |
| `AuthorizationMatrixTest` | 7 | 24 permissions x 5 roles, seeded grants |
| `EmailNormalizationTest` | (Module 01) | 4.9 |

`DatabaseInvariantTest` asserts against raw SQL rather than the ORM, because the ORM cannot
express "insert a second active row and watch the database refuse".

The matrix test is the one that would have caught 4.10 by producing green tests that
asserted nothing.

---

## 6. Live verification performed

Run against a real `artisan serve`, real HTTP requests, real Sanctum tokens - not only
through the test kernel.

| Area | Verified |
|---|---|
| Auth | Login as admin and student, bearer token accepted, 401 without a token |
| Authorization | Student gets 403 on context and sessions; admin gets 200 |
| School | `GET` 200; `PUT` 200 with `short_name` folded to `GFS` and `HTTPS://Greenfield.Example` stored as `https://greenfield.example`; `PATCH` correctly 405 |
| Context | 200 with session `2026/2027` and term `First Term` |
| Sessions | List with `meta.total`; `2027 - 2028` created and stored as `2027/2028`; duplicate name 422 with the message; activate 200 and previous completed |
| Terms | Created inside session; term outside session dates 422; `GET /terms/{id}` shows its session; amend 200 |
| Structure | Level, class and section created in sequence; filter by `class_level_id`; `sections_count` and `classes_count` present |
| Errors | 404 `{"message":"Resource not found."}`; 401 `{"message":"Unauthenticated."}`; 422 with field errors |
| Pagination | Top-level keys exactly `data, meta, links`; `data` is a flat array; `data.data` absent |
| Filters | `active_only` true/false/1/0/maybe; `per_page` 15/100/1000000/0; non-numeric and unknown parent ids; invalid `status` - all as documented |

The dev database was reset with `migrate:fresh --seed` afterwards, so none of the smoke-test
data remains.

---

## 7. Residual risks and future work

1. **No `sort` parameter.** Each list has one fixed order. If a client needs "sessions by
   name", the service needs a second order, not a user-supplied column name.
2. **No bulk endpoints.** Class levels, classes and sections are one record per request.
   A school importing a hundred classes will make a hundred requests. A future import
   endpoint is a real need, and a separate permission - not a widened `create`.
3. **`registration_number` is not unique.** Deliberate, because two schools in different
   countries may share one. It becomes a problem only if a second school is ever added.
4. **The context is three queries per call.** Correctly so, and irrelevant at this size.
   Worth revisiting only if a high-traffic endpoint starts calling it per request.
5. **`per_page=100` still allows 100 rows.** A future export must not be a larger
   `per_page`; it needs its own streaming endpoint.
6. **No optimistic locking.** Two administrators amending the same class can silently
   overwrite each other. `updated_at` is returned, so a client *can* implement
   compare-and-set, but the API does not enforce it.
7. **Terms cannot be reparented.** A term cannot be moved to another session, by design.
   A school that has to correct a term filed under the wrong year must delete and recreate
   it. Low impact while terms carry no other references.

---

## 8. Preservation statement (verified)

- No historical migration was edited. `git status` shows six **new** migration files and no
  modification to any existing one.
- Module 01 behaviour is unchanged, with one exception: `app/Models/User.php` was fixed
  (4.9). That file previously contained a bug that broke every login; the fix restores the
  documented Module 01 behaviour rather than changing it.
- `tests/Feature/Auth/*` pass unchanged.
- `DatabaseSeeder` calls the Module 02 seeders **after** the Module 01 ones and does not
  wrap them in `WithoutModelEvents`, so user/permission events still fire.

---

## 9. Out of scope - confirmed not built

No `school_id` column, tenancy, branches, students, enrollment, subjects, teachers,
assessments, results, grading, promotion, attendance, timetables, fees, notifications,
announcements, file upload, exports, `sort`/`direction` parameters, or a register endpoint.

---

## 10. How to run it

```dotenv
# .env
SCHOOL_NAME="Greenfield International School"
SCHOOL_SHORT_NAME="GIS"
SUPER_ADMIN_EMAIL=superadmin@example.test
SUPER_ADMIN_PASSWORD=<set a real password>
```

```bash
composer install
php artisan migrate:fresh --seed
php artisan serve
```

```bash
# verify
vendor\bin\pint --test
php artisan test
php artisan route:list --path=api
```

A fresh seed gives one school, one active session `2026/2027`, three terms of which
`First Term` is active, and four class levels. Classes and sections are left empty for the
school to build.

Then:

```bash
curl -X POST http://127.0.0.1:8000/api/v1/auth/login \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"email":"superadmin@example.test","password":"<password>"}'

curl http://127.0.0.1:8000/api/v1/academic-context \
  -H "Accept: application/json" -H "Authorization: Bearer <token>"
```

The second call is the one to try first: it answers "is this school configured, and what
year and term is it in" in a single request, which is the question every client opens with.
