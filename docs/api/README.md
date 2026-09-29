# API reference

One document per module. Each is self-contained: conventions, resources, every endpoint,
permissions, and the client-side checklist.

| Module | Document | Covers |
|---|---|---|
| 01 | [authentication.md](authentication.md) | Login, logout, `me`, email verification, password reset, roles and permissions, the user resource |
| 02 | [school-configuration.md](school-configuration.md) | School profile, academic sessions, terms, academic context, class levels, classes, sections |
| 03 | [staff-management.md](staff-management.md) | Staff listing and search, creating a staff record with its login account, employment status, terminating an employment |
| 04 | [student-management.md](student-management.md) | The pupil roll: creating a pupil with no login, derived student numbers, roll status, why a pupil is never deleted |
| 05 | [admission-management.md](admission-management.md) | Recording and deciding admissions, the `admit`/`reject`/`withdraw` workflow, and how admitting an applicant creates a student |
| 06 | [enrollment-management.md](enrollment-management.md) | The authoritative academic placement - student, session, class, section - one row per session, and why `students` still has no `current_class_id` |
| 07 | [subject-management.md](subject-management.md) | The subject catalogue and class-subject offerings - Mathematics vs "JSS 2 teaches Mathematics" - and why neither table has a teacher column |
| 08 | [teacher-assignment.md](teacher-assignment.md) | Assigning teaching staff to class subjects for a session, the single-active-teacher rule, and reassignment via `end` then `create` |
| 09 | [assessment-configuration.md](assessment-configuration.md) | The assessment type catalogue and the assessments configured against a class subject and term - CA1/CA2/CA3, max score and weight - and why there is no score, grade or result column yet |
| 10 | [score-management.md](score-management.md) | Recording what a student obtained against a configured assessment, single or bulk, the enrollment (not student) relationship, live max-score validation, and the teacher-assignment scope that restricts every read and write |
| 11 | [grading.md](grading.md) | Class-level-scoped grading scales and their percentage bands, inclusive boundary rules, overlap/gap handling, and the read-only percentage-to-grade calculation operation |
| 12 | [result-compilation.md](result-compilation.md) | Compiling raw assessment scores into a subject result for one enrollment, class subject and term - the weighting rules, the missing-scores policy, idempotent recompilation, and why there is no create/update/delete endpoint |

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
3. **03** - staff establishment
4. **04** - the pupil roll
5. **05** - admission management
6. **06** - student enrollment
7. **07** - subject catalogue and class-subject offerings
8. **08** - teacher assignment
9. **09** - assessment configuration
10. **10** - score management
11. **11** - grading
12. **12** - result compilation

Module 04 depends on Module 01 only. It has no academic dependency, which is the point: a
pupil exists before they are admitted, placed in a class, or given a portal login, so the roll
needs nothing from Module 02 in order to be correct.

Module 05 depends on Module 01 (auth), Module 02 (the academic session an admission targets)
and Module 04 (it creates students through `StudentService`, never a second implementation of
what a pupil's row looks like) - see
[admission-management.md](admission-management.md) §0.

Module 06 depends on Module 01, Module 02 (the session, class and section a placement names)
and Module 04 (the student being placed) - but deliberately **not** on Module 05: an admission
is never required before enrollment, because Module 04's own `POST /students` remains an
independent path to a student. See
[enrollment-management.md](enrollment-management.md) §0.

Module 07 depends on Module 01 and Module 02 (a class subject offers a subject to an existing,
active class) only - not on Module 04, 05 or 06. A subject exists independently of any student
ever being enrolled to study it. See
[subject-management.md](subject-management.md) §0.

Module 08 depends on Module 01, Module 03 (the teaching staff member) and Module 07 (the class
subject being assigned) - not on Module 04, 05 or 06. Unlike a class subject itself, WHO
teaches it is session-scoped, so Module 08 also depends on Module 02's academic session. See
[teacher-assignment.md](teacher-assignment.md) §0.

Module 09 depends on Module 02 (the term an assessment is configured against - the academic
session is derived through it, never duplicated) and Module 07 (the class subject an assessment
belongs to) - not on Module 03, 04, 05, 06 or 08. Assessment configuration is independent of who
teaches a class subject; a future scores module is what will connect the two. See
[assessment-configuration.md](assessment-configuration.md) §0.

Module 10 depends on Module 06 (the enrollment a score is recorded against), Module 08 (the
teacher-assignment relationship that scopes who may record it) and Module 09 (the assessment a
score belongs to, including its live `max_score`) - not on Module 03, 04, 05 or 07 directly.
See [score-management.md](score-management.md) §0 and §4.

Module 11 depends on Module 02 (the class levels a scale is scoped to) only - not on Module
03, 04, 05, 06, 07, 08, 09 or 10. A grading scale interprets a percentage; it does not
reference an assessment, an enrollment or a score at all. A future grading/result-compilation
module is expected to compute a percentage (Module 10 already exposes one) and pass it to this
module's calculation operation, not the other way around. See [grading.md](grading.md) §0.

Module 12 depends on Module 06 (the enrollment a result is compiled for), Module 09 (the
assessments a result aggregates, including their live weights), Module 10 (the scores recorded
against those assessments) and Module 11 (the grading scale a complete result is interpreted
through) - not on Module 03, 04, 05, 07 or 08 directly, though it reuses Module 08's own
teacher-assignment scoping technique. It is the first module to require three foreign keys at
once (`enrollment_id`, `class_subject_id`, `term_id`), none derivable from either of the other
two. See [result-compilation.md](result-compilation.md) §0.

The Super Admin account, the school profile and the development staff and pupil records are
created by seeders, not by the API: there is no `POST /auth/register` and no `POST /school`,
because the school is a singleton and accounts are provisioned by an administrator.

`POST /staff` does create an account, because a staff record and its login are one
indivisible fact - see [staff-management.md](staff-management.md) §3.2. `POST /students` does
**not**, and that asymmetry is deliberate rather than a missing feature: a pupil does not need
a login to exist - see [student-management.md](student-management.md) §3.1.
