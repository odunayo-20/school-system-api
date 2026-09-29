<?php

use App\Enums\AdmissionStatus;
use App\Enums\Role;
use App\Enums\StaffType;
use App\Enums\UserStatus;
use App\Models\AcademicSession;
use App\Models\Admission;
use App\Models\ClassLevel;
use App\Models\Permission;
use App\Models\Role as RoleModel;
use App\Models\School;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Often, you may
| need to change it using the "pest()->extend()" function.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
|
| Shared helpers for the authentication and authorization feature tests. Roles and
| Module 01 permissions are seeded from Tests\TestCase::setUp().
|
*/

/**
 * Log in through the real endpoint and return the plain text bearer token.
 */
function loginAs(User $user, string $password = 'password'): string
{
    $response = test()->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => $password,
    ]);

    $response->assertOk();

    return $response->json('data.token');
}

function userWithRole(Role $role, array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'role_id' => RoleModel::where('name', $role->value)->value('id'),
        'password' => Hash::make('password'),
    ], $attributes));
}

/**
 * A user whose email address has not been verified yet.
 */
function unverifiedUser(Role $role): User
{
    $user = userWithRole($role);

    $user->forceFill(['email_verified_at' => null])->save();

    return $user;
}

/**
 * Register throwaway routes so authorization middleware can be exercised without
 * creating placeholder endpoints for modules that do not exist yet.
 */
function registerAuthorizationTestRoutes(): void
{
    Route::middleware(['auth:api', 'active'])
        ->get('/_test/any-authenticated', fn () => response()->json(['ok' => true]));

    Route::middleware(['auth:api', 'active', 'role:ADMIN'])
        ->get('/_test/admin-only', fn () => response()->json(['ok' => true]));

    Route::middleware(['auth:api', 'active', 'permission:users.create'])
        ->post('/_test/permission-gated', fn () => response()->json(['ok' => true]));

    // A permission no module owns, so this route stands in for a future module's. It was
    // originally gated on "students.view" back when that was hypothetical; Module 04 made
    // it real and seeded it to REGISTRAR, so the test using this route now gates on a name
    // no seeder will ever create. The route name and the test must be changed together.
    Route::middleware(['auth:api', 'active', 'permission:graduation_records.view'])
        ->get('/_test/future-permission', fn () => response()->json(['ok' => true]));

    Route::middleware(['auth:api', 'active', 'verified'])
        ->get('/_test/verified-only', fn () => response()->json(['ok' => true]));
}

function grantPermission(User $user, string $name): void
{
    $user->directPermissions()->attach(
        Permission::query()->firstOrCreate(
            ['name' => $name],
            ['label' => 'Seeded by test'],
        )
    );

    $user->unsetRelation('directPermissions')->unsetRelation('role');
}

/**
 * Log in and return the test case with the bearer header already attached, so a test reads
 * as one call per request:
 *
 *     asUser($admin)->getJson('/api/v1/academic-sessions')->assertOk();
 *
 * Two pieces of per-test state are cleared first, so that a test which acts as more than
 * one user is testing what it appears to test. A test process reuses one application
 * instance, and both of these survive from one request to the next:
 *
 *   - the test case's default headers, which still carry the previous user's bearer token;
 *   - the user the auth manager already resolved for the guard.
 *
 * Without clearing them, the login request below would be sent *with* the previous token
 * and the guard would resolve that user again. The next request, carrying the new token,
 * would then still be authorized as the old one, and any assertion about the new user's
 * rights would quietly be an assertion about the previous user's.
 *
 * @return TestCase
 */
function asUser(User $user, string $password = 'password')
{
    forgetResolvedUser();
    test()->withHeader('Authorization', '');

    $token = loginAs($user, $password);

    // The login request resolved nothing (it carried no token), but a guard may still hold
    // a user from earlier in the test, so clear it once more before the first real request.
    forgetResolvedUser();

    return test()->withHeader('Authorization', 'Bearer '.$token);
}

/**
 * Attach a token that has already been issued, so a test making many requests as one user
 * logs in once.
 *
 * asUser() is not free: it posts to the real login endpoint, and that route carries
 * throttle:login at five attempts a minute per email address. A test that loops over five
 * query strings calling asUser() each time therefore gets a 429 on the sixth, which has
 * nothing to do with the filters it is checking. Issuing the token once and reusing it
 * keeps the limiter out of the way without weakening it.
 *
 * @return TestCase
 */
function withToken(string $token)
{
    forgetResolvedUser();

    return test()->withHeader('Authorization', 'Bearer '.$token);
}

/**
 * A school that is fully configured: a profile, a current session and the term inside it
 * that contains today.
 *
 * Returning the models rather than just a truthy value lets a test assert against the
 * current session and term by identity, which is what nearly every academic assertion
 * actually needs.
 *
 * @return array{0: School, 1: AcademicSession, 2: Term}
 */
function configuredSchool(?string $sessionName = null): array
{
    $school = School::factory()->active()->create();

    $session = AcademicSession::factory()->active()->create(array_filter([
        'name' => $sessionName,
    ]));

    $term = Term::factory()->active()->create([
        'academic_session_id' => $session->getKey(),
        'term_number' => 1,
        'start_date' => $session->start_date,
        'end_date' => $session->end_date,
    ]);

    return [$school, $session, $term];
}

/**
 * A test process handles several requests against one application instance, and the
 * auth manager caches the resolved user per guard. Forget the guards so the next
 * request re-resolves the user from its token, exactly as a real request would.
 */
function forgetResolvedUser(): void
{
    app('auth')->forgetGuards();
}

/*
|--------------------------------------------------------------------------
| Staff module helpers (Module 03)
|--------------------------------------------------------------------------
|
| A valid create payload, so each test states only the field it is actually about. The
| password satisfies the shared PasswordRule: eight characters with upper case, lower
| case, a number and a symbol.
|
*/

function staffCreatePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Amina Yusuf',
        'email' => 'amina@example.test',
        'password' => 'Str0ng!Passw0rd',
        'password_confirmation' => 'Str0ng!Passw0rd',
        'staff_type' => 'TEACHING',
    ], $overrides);
}

/**
 * A valid amend payload. PUT is a whole-record write, so name and staff_type are always
 * present unless a test is deliberately omitting one.
 */
function staffUpdatePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Amina Yusuf',
        'staff_type' => 'TEACHING',
    ], $overrides);
}

/**
 * A staff record with a linked STAFF user, built through the factory rather than the API
 * so a test about the API is not also testing account creation.
 */
function staffMember(?StaffType $staffType = null, array $staffAttributes = []): Staff
{
    $staff = Staff::factory()->create(array_filter([
        'staff_type' => $staffType,
    ], fn (mixed $value): bool => ! is_null($value)));

    $staff->forceFill($staffAttributes)->save();

    return $staff->refresh();
}

/*
|--------------------------------------------------------------------------
| Student module helpers (Module 04)
|--------------------------------------------------------------------------
|
| A valid create payload, so each test states only the field it is actually about.
|
| Note how much smaller this is than staffCreatePayload(). That difference is the module:
| there are no credentials here, because creating a pupil does not create a login, and no
| staff_type because "TEACHING" is a fact about a job and not about a child.
|
*/

function studentCreatePayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Amina',
        'last_name' => 'Yusuf',
    ], $overrides);
}

/**
 * A valid amend payload. PUT is a whole-record write, so first_name is always present unless
 * a test is deliberately omitting it.
 */
function studentUpdatePayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Amina',
        'last_name' => 'Yusuf',
    ], $overrides);
}

/**
 * A pupil record with no login account, built through the factory rather than the API so a
 * test about the API is not also testing record creation.
 *
 * user_id is null, which is the common case and the reason the column is nullable.
 */
function pupil(array $studentAttributes = []): Student
{
    $student = Student::factory()->create();

    $student->forceFill($studentAttributes)->save();

    return $student->refresh();
}

/**
 * A pupil WITH a portal account, for the resource and relationship tests.
 *
 * The account's UserStatus is deliberately not coupled to the pupil's roll status: one is who
 * can log in, the other is who is on the roll, and a test that set both would be asserting
 * that the two are the same question.
 */
function pupilWithAccount(?UserStatus $accountStatus = null): Student
{
    $student = Student::factory()->withAccount()->create();

    if ($accountStatus !== null) {
        $student->user->forceFill(['status' => $accountStatus])->save();
    }

    return $student->refresh();
}

/*
|--------------------------------------------------------------------------
| Admission module helpers (Module 05)
|--------------------------------------------------------------------------
|
| A valid create payload always carries an academic_session_id, because unlike the pupil
| roll an admission is meaningless without the intake it targets. A caller that does not
| care which session is used gets a fresh UPCOMING one created for it, mirroring the way
| configuredSchool() hands tests a ready-made academic context rather than making every test
| construct one by hand.
|
*/

function admissionCreatePayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Amina',
        'last_name' => 'Yusuf',
        'academic_session_id' => AcademicSession::factory()->create()->id,
    ], $overrides);
}

/**
 * A valid amend payload. PUT is a whole-record write, so first_name and academic_session_id
 * are always present unless a test is deliberately omitting one.
 */
function admissionUpdatePayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Amina',
        'last_name' => 'Yusuf',
        'academic_session_id' => AcademicSession::factory()->create()->id,
    ], $overrides);
}

/**
 * A pending admission, built through the factory rather than the API so a test about the API
 * is not also testing record creation.
 */
function pendingAdmission(array $attributes = []): Admission
{
    $admission = Admission::factory()->create(array_filter([
        'academic_session_id' => $attributes['academic_session_id'] ?? null,
        'entry_class_level_id' => $attributes['entry_class_level_id'] ?? null,
    ], fn (mixed $value): bool => ! is_null($value)));

    $admission->forceFill(collect($attributes)->except(['academic_session_id', 'entry_class_level_id'])->all())->save();

    return $admission->refresh();
}

/**
 * An admission that has already been decided and, for ADMITTED, carries the student it
 * created - built directly rather than through admit(), so a test about admit() is not
 * circularly dependent on the very method it is testing.
 */
function decidedAdmission(AdmissionStatus $status, array $attributes = []): Admission
{
    $admission = pendingAdmission($attributes);

    $admission->forceFill(['status' => $status, 'decided_at' => now()]);

    if ($status === AdmissionStatus::ADMITTED) {
        $admission->student_id = Student::factory()->create([
            'first_name' => $admission->first_name,
            'last_name' => $admission->last_name,
        ])->id;
    }

    $admission->save();

    return $admission->refresh();
}

/**
 * A class level a test can safely target as an entry level: ACTIVE, matching the rule that
 * only a selectable class level may be applied for.
 */
function selectableClassLevel(): ClassLevel
{
    return ClassLevel::factory()->create();
}
