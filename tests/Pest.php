<?php

use App\Enums\AdmissionStatus;
use App\Enums\AttendanceStatus;
use App\Enums\CatalogStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\PromotionDecision;
use App\Enums\ResultStatus;
use App\Enums\Role;
use App\Enums\StaffType;
use App\Enums\TeacherAssignmentStatus;
use App\Enums\UserStatus;
use App\Models\AcademicSession;
use App\Models\Admission;
use App\Models\Assessment;
use App\Models\AssessmentType;
use App\Models\Attendance;
use App\Models\ClassLevel;
use App\Models\ClassSubject;
use App\Models\Enrollment;
use App\Models\GradingScale;
use App\Models\Permission;
use App\Models\Promotion;
use App\Models\Result;
use App\Models\Role as RoleModel;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Score;
use App\Models\Section;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\Term;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Services\Promotion\PromotionService;
use App\Services\Result\ResultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
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

/*
|--------------------------------------------------------------------------
| Enrollment module helpers (Module 06)
|--------------------------------------------------------------------------
|
| A valid create payload always carries fresh, valid references: an ACTIVE student, a
| non-completed academic session, and a section that genuinely belongs to the class named
| alongside it. A caller overriding one FK is responsible for the consistency of what it
| overrides - the same posture admissionCreatePayload() takes for academic_session_id.
|
*/

/**
 * An ACTIVE student, eligible for enrollment. A thin wrapper over the Module 04 helper so a
 * reader of an enrollment test does not have to know pupil() is where an eligible student
 * comes from.
 */
function eligibleStudent(array $attributes = []): Student
{
    return pupil($attributes);
}

/**
 * A session that is open for new placements: not COMPLETED. AcademicSession::factory()'s own
 * default is UPCOMING, so this is a thin, self-documenting alias for it.
 */
function eligibleSession(): AcademicSession
{
    return AcademicSession::factory()->create();
}

/**
 * A section whose whole hierarchy - its class, and that class's class level - is ACTIVE,
 * matching the rule that a new enrollment may only be made into a fully active hierarchy.
 * Section::factory()'s own default (and its class and class level factories in turn) is
 * ACTIVE, so this is a thin, self-documenting alias for it.
 */
function activeSection(): Section
{
    return Section::factory()->create();
}

function enrollmentCreatePayload(array $overrides = []): array
{
    $section = activeSection();
    $session = eligibleSession();

    return array_merge([
        'student_id' => eligibleStudent()->id,
        'academic_session_id' => $session->id,
        'school_class_id' => $section->school_class_id,
        'section_id' => $section->id,
        'enrollment_date' => $session->start_date->toDateString(),
    ], $overrides);
}

/**
 * A valid amend payload. PUT touches only these two fields.
 */
function enrollmentUpdatePayload(array $overrides = []): array
{
    return array_merge([
        'enrollment_date' => now()->toDateString(),
        'notes' => null,
    ], $overrides);
}

/**
 * An active enrollment, built through the factory rather than the API so a test about the API
 * is not also testing record creation.
 */
function activeEnrollment(array $attributes = []): Enrollment
{
    $enrollment = Enrollment::factory()->create(array_filter([
        'academic_session_id' => $attributes['academic_session_id'] ?? null,
        'school_class_id' => $attributes['school_class_id'] ?? null,
        'section_id' => $attributes['section_id'] ?? null,
        'student_id' => $attributes['student_id'] ?? null,
    ], fn (mixed $value): bool => ! is_null($value)));

    $enrollment->forceFill(
        collect($attributes)->except(['academic_session_id', 'school_class_id', 'section_id', 'student_id'])->all()
    )->save();

    return $enrollment->refresh();
}

/**
 * An enrollment that has already ended (WITHDRAWN or CANCELLED) - built directly rather than
 * through withdraw()/cancel(), so a test about those methods is not circularly dependent on
 * the very transition it is testing.
 */
function decidedEnrollment(EnrollmentStatus $status, array $attributes = []): Enrollment
{
    $enrollment = activeEnrollment($attributes);

    $enrollment->forceFill(['status' => $status, 'status_changed_at' => now()])->save();

    return $enrollment->refresh();
}

/*
|--------------------------------------------------------------------------
| Subject module helpers (Module 07)
|--------------------------------------------------------------------------
|
| name and code are generated from Faker's unique() modifier rather than a fixed literal,
| unlike staffCreatePayload()/studentCreatePayload(): a subject's name and code are globally
| unique, and a test that calls this helper more than once in a row (deliberately, to assert
| a duplicate is refused) must not collide with ITSELF before it ever reaches the assertion
| under test.
|
*/

function subjectCreatePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Subject '.fake()->unique()->numberBetween(1, 1000000),
        'code' => mb_strtoupper(fake()->unique()->lexify('????')),
    ], $overrides);
}

/**
 * A valid amend payload. PUT is a whole-record write, so name and code are always present
 * unless a test is deliberately omitting one.
 */
function subjectUpdatePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Subject '.fake()->unique()->numberBetween(1, 1000000),
        'code' => mb_strtoupper(fake()->unique()->lexify('????')),
    ], $overrides);
}

/**
 * A subject built through the factory rather than the API, so a test about the API is not
 * also testing record creation.
 */
function catalogSubject(array $attributes = []): Subject
{
    return Subject::factory()->create($attributes);
}

/**
 * A class whose whole hierarchy - the class itself, and its class level - is ACTIVE, matching
 * the rule that a new class subject may only be offered into a fully active hierarchy. Named
 * to match selectableClassLevel() above.
 */
function selectableSchoolClass(): SchoolClass
{
    return SchoolClass::factory()->create();
}

function classSubjectCreatePayload(array $overrides = []): array
{
    return array_merge([
        'school_class_id' => selectableSchoolClass()->id,
        'subject_id' => catalogSubject()->id,
    ], $overrides);
}

/**
 * An active class subject, built through the factory rather than the API.
 */
function activeClassSubject(array $attributes = []): ClassSubject
{
    return ClassSubject::factory()->create($attributes);
}

/*
|--------------------------------------------------------------------------
| Teacher assignment module helpers (Module 08)
|--------------------------------------------------------------------------
|
| A valid create payload always carries fresh, valid references: a TEACHING, actively
| employed staff member, an active class subject whose whole hierarchy is active, and a
| non-completed academic session - the same posture enrollmentCreatePayload() and
| classSubjectCreatePayload() take for their own foreign keys.
|
*/

/**
 * An actively employed TEACHING staff member, eligible to be assigned. A thin wrapper over
 * the Module 03 helper so a reader of an assignment test does not have to know staffMember()
 * is where an eligible teacher comes from.
 */
function eligibleTeacher(array $staffAttributes = []): Staff
{
    return staffMember(StaffType::TEACHING, $staffAttributes);
}

function assignmentCreatePayload(array $overrides = []): array
{
    return array_merge([
        'teaching_staff_id' => eligibleTeacher()->id,
        'class_subject_id' => activeClassSubject()->id,
        'academic_session_id' => eligibleSession()->id,
    ], $overrides);
}

/**
 * An active teacher assignment, built through the factory rather than the API so a test
 * about the API is not also testing record creation.
 */
function activeAssignment(array $attributes = []): TeacherAssignment
{
    return TeacherAssignment::factory()->create($attributes);
}

/**
 * An assignment that has already ended (ENDED or CANCELLED) - built directly rather than
 * through end()/cancel(), so a test about those methods is not circularly dependent on the
 * very transition it is testing.
 */
function decidedAssignment(TeacherAssignmentStatus $status, array $attributes = []): TeacherAssignment
{
    $assignment = activeAssignment($attributes);

    $assignment->forceFill(['status' => $status, 'active_marker' => null, 'ended_at' => now()])->save();

    return $assignment->refresh();
}

/*
|--------------------------------------------------------------------------
| Assessment configuration module helpers (Module 09)
|--------------------------------------------------------------------------
|
| A valid create payload always carries fresh, valid references: an active class subject
| whose whole hierarchy is active, a non-completed term, and an active assessment type - the
| same posture assignmentCreatePayload() and classSubjectCreatePayload() take for their own
| foreign keys.
|
*/

function assessmentTypeCreatePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Assessment Type '.fake()->unique()->numberBetween(1, 1000000),
        'code' => mb_strtoupper(fake()->unique()->lexify('????')),
    ], $overrides);
}

/**
 * A valid amend payload. PUT is a whole-record write, so name and code are always present
 * unless a test is deliberately omitting one.
 */
function assessmentTypeUpdatePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Assessment Type '.fake()->unique()->numberBetween(1, 1000000),
        'code' => mb_strtoupper(fake()->unique()->lexify('????')),
    ], $overrides);
}

/**
 * An assessment type built through the factory rather than the API, so a test about the API
 * is not also testing record creation.
 */
function catalogAssessmentType(array $attributes = []): AssessmentType
{
    return AssessmentType::factory()->create($attributes);
}

/**
 * A term that is open for new assessments: not COMPLETED. Term::factory()'s own default is
 * UPCOMING, so this is a thin, self-documenting alias for it - matching eligibleSession()'s
 * identical reasoning for academic sessions.
 */
function eligibleTerm(): Term
{
    return Term::factory()->create();
}

function assessmentCreatePayload(array $overrides = []): array
{
    return array_merge([
        'class_subject_id' => activeClassSubject()->id,
        'term_id' => eligibleTerm()->id,
        'assessment_type_id' => catalogAssessmentType()->id,
        'name' => 'CA '.fake()->unique()->numberBetween(1, 1000000),
        'max_score' => 20,
    ], $overrides);
}

/**
 * A valid amend payload. PUT is a whole-record write, so name and max_score are always
 * present unless a test is deliberately omitting one.
 */
function assessmentUpdatePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'CA '.fake()->unique()->numberBetween(1, 1000000),
        'max_score' => 20,
    ], $overrides);
}

/**
 * An active, configured assessment, built through the factory rather than the API so a test
 * about the API is not also testing record creation.
 */
function activeAssessment(array $attributes = []): Assessment
{
    return Assessment::factory()->create($attributes);
}

/**
 * An assessment that has been retired (INACTIVE or ARCHIVED) - built directly rather than
 * through update(), so a test about the update endpoint is not circularly dependent on the
 * very transition it is testing.
 */
function retiredAssessment(CatalogStatus $status, array $attributes = []): Assessment
{
    $assessment = activeAssessment($attributes);

    $assessment->forceFill(['status' => $status])->save();

    return $assessment->refresh();
}

/*
|--------------------------------------------------------------------------
| Score module helpers (Module 10)
|--------------------------------------------------------------------------
|
| Unlike every FK pair before it, an assessment and an enrollment do not automatically agree
| on class or session merely by both existing - ScoreService::assertContextMatches() requires
| it explicitly. matchedScoreContext() is the one helper this module needs that none of Modules
| 05-09 did: a school class, a class subject, a session and term, and an enrollment placed in
| that SAME class and session, built together so a test that wants a genuinely valid score does
| not have to wire five models by hand.
|
*/

/**
 * An assessment and an enrollment that agree on class and academic session - the exact
 * alignment a score requires. Returned as a pair rather than a single opaque object, matching
 * configuredSchool()'s own reasoning: a test asserts against the pieces, not a container.
 *
 * @return array{0: Assessment, 1: Enrollment}
 */
function matchedScoreContext(array $assessmentAttributes = [], array $enrollmentAttributes = []): array
{
    $class = selectableSchoolClass();
    $classSubject = activeClassSubject(['school_class_id' => $class->id]);
    $session = eligibleSession();
    $term = Term::factory()->forSession($session, 1)->create();
    $section = Section::factory()->within($class, 'A', 'A')->create();

    $assessment = activeAssessment(array_merge([
        'class_subject_id' => $classSubject->id,
        'term_id' => $term->id,
    ], $assessmentAttributes));

    $enrollment = activeEnrollment(array_merge([
        'school_class_id' => $class->id,
        'section_id' => $section->id,
        'academic_session_id' => $session->id,
    ], $enrollmentAttributes));

    return [$assessment, $enrollment];
}

/**
 * A valid create payload, built from a freshly matched assessment/enrollment pair. A caller
 * overriding assessment_id or enrollment_id is responsible for the consistency of what it
 * overrides - the same posture assignmentCreatePayload() takes for its own foreign keys.
 */
function scoreCreatePayload(array $overrides = []): array
{
    [$assessment, $enrollment] = matchedScoreContext();

    return array_merge([
        'assessment_id' => $assessment->id,
        'enrollment_id' => $enrollment->id,
        'score' => 15,
    ], $overrides);
}

/**
 * A valid amend payload. PUT touches only score and remarks - assessment_id and enrollment_id
 * have no key to send at all, see UpdateScoreRequest.
 */
function scoreUpdatePayload(array $overrides = []): array
{
    return array_merge([
        'score' => 18,
        'remarks' => null,
    ], $overrides);
}

/**
 * A recorded score, built through the factory rather than the API so a test about the API is
 * not also testing record creation. Its assessment and enrollment are NOT context-matched by
 * default (ScoreFactory builds each independently) - tests that need a genuinely valid,
 * scoreable context use matchedScoreContext() instead and build the Score from its pair.
 */
function recordedScore(array $attributes = []): Score
{
    return Score::factory()->create($attributes);
}

/**
 * An actively employed TEACHING staff member with an ACTIVE assignment to the given class
 * subject for the given session - eligible, under ScoreService's own scope, to enter or amend
 * scores for assessments configured against it.
 */
function teacherAssignedTo(ClassSubject $classSubject, AcademicSession $session): Staff
{
    $teacher = eligibleTeacher();

    TeacherAssignment::factory()
        ->forTeacher($teacher)
        ->forClassSubject($classSubject)
        ->forSession($session)
        ->create();

    return $teacher;
}

/**
 * $count enrollments, all placed in the SAME class and session as the given assessment - the
 * shape a real class roster submission has. Returns plain enrollment ids, which is all a bulk
 * payload needs.
 *
 * @return list<int>
 */
/*
|--------------------------------------------------------------------------
| Grading module helpers (Module 11)
|--------------------------------------------------------------------------
|
| standardGradingBands() is the one fixture nearly every test needs: five bands covering
| 0-100 with no gaps or overlaps, exactly the shape a real scale has. Individual tests that
| care about a specific boundary/overlap/gap build their own `items` array instead of using
| this helper, the same posture assessmentCreatePayload() takes toward its own defaults.
|
*/

/**
 * @return list<array{grade: string, min_percentage: float, max_percentage: float, grade_point: float, remark: string}>
 */
function standardGradingBands(): array
{
    return [
        ['grade' => 'A', 'min_percentage' => 70, 'max_percentage' => 100, 'grade_point' => 5, 'remark' => 'Excellent'],
        ['grade' => 'B', 'min_percentage' => 60, 'max_percentage' => 69.99, 'grade_point' => 4, 'remark' => 'Very Good'],
        ['grade' => 'C', 'min_percentage' => 50, 'max_percentage' => 59.99, 'grade_point' => 3, 'remark' => 'Good'],
        ['grade' => 'D', 'min_percentage' => 40, 'max_percentage' => 49.99, 'grade_point' => 2, 'remark' => 'Fair'],
        ['grade' => 'F', 'min_percentage' => 0, 'max_percentage' => 39.99, 'grade_point' => 0, 'remark' => 'Fail'],
    ];
}

function gradingScaleCreatePayload(array $overrides = []): array
{
    return array_merge([
        'class_level_id' => ClassLevel::factory()->create()->id,
        'name' => 'Grading Scale '.fake()->unique()->numberBetween(1, 1000000),
        'code' => mb_strtoupper(fake()->unique()->lexify('????')),
        'items' => standardGradingBands(),
    ], $overrides);
}

/**
 * A valid amend payload. PUT is a whole-record write, so name, code and the full items array
 * are always present unless a test is deliberately omitting one.
 */
function gradingScaleUpdatePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Grading Scale '.fake()->unique()->numberBetween(1, 1000000),
        'code' => mb_strtoupper(fake()->unique()->lexify('????')),
        'items' => standardGradingBands(),
    ], $overrides);
}

/**
 * An active grading scale with the standard five bands already attached, built through the
 * factory rather than the API so a test about the API is not also testing record creation.
 */
function configuredGradingScale(array $attributes = []): GradingScale
{
    return GradingScale::factory()->configureWithStandardBands()->create($attributes);
}

function rosterEnrollments(Assessment $assessment, int $count): array
{
    $class = $assessment->classSubject->schoolClass;
    $session = $assessment->term->academicSession;

    // matchedScoreContext() already places a section named 'A' on this class while building
    // the assessment/enrollment pair, so reuse whatever section the class already has rather
    // than colliding with it on the (school_class_id, code) unique index.
    $section = Section::query()->where('school_class_id', $class->id)->first()
        ?? Section::factory()->within($class, 'A', 'A')->create();

    return collect(range(1, $count))->map(fn (): int => activeEnrollment([
        'school_class_id' => $class->id,
        'section_id' => $section->id,
        'academic_session_id' => $session->id,
    ])->id)->all();
}

/*
|--------------------------------------------------------------------------
| Result compilation module helpers (Module 12)
|--------------------------------------------------------------------------
|
| compile() needs everything matchedScoreContext() already assembles (an assessment and an
| enrollment agreeing on class and session) PLUS a recorded Score against that assessment for
| the result to calculate as COMPLETE rather than INCOMPLETE. resultCompilationContext() builds
| all of it together so a test that wants a genuinely compilable result does not have to wire
| six models by hand.
|
*/

/**
 * An assessment (with a recorded score) and a matched enrollment - everything compile() needs
 * to produce a COMPLETE result for a single-assessment class subject. Returns the pieces
 * rather than a container, matching matchedScoreContext()'s own reasoning.
 *
 * @return array{0: Assessment, 1: Enrollment, 2: Score}
 */
function resultCompilationContext(array $assessmentAttributes = [], array $enrollmentAttributes = [], float $score = 15): array
{
    [$assessment, $enrollment] = matchedScoreContext($assessmentAttributes, $enrollmentAttributes);

    $recordedScore = Score::factory()
        ->forAssessment($assessment)
        ->forEnrollment($enrollment)
        ->create(['score' => $score]);

    return [$assessment, $enrollment, $recordedScore];
}

/**
 * A valid compile payload, built from a freshly matched, fully-scored context. A caller
 * overriding enrollment_id/class_subject_id/term_id is responsible for the consistency of what
 * it overrides - the same posture scoreCreatePayload() takes for its own foreign keys.
 */
function compileResultPayload(array $overrides = []): array
{
    [$assessment, $enrollment] = resultCompilationContext();

    return array_merge([
        'enrollment_id' => $enrollment->id,
        'class_subject_id' => $assessment->class_subject_id,
        'term_id' => $assessment->term_id,
    ], $overrides);
}

/**
 * A compiled result, built through the service's own persist path (compile()) rather than the
 * factory, so its percentage/grade/status genuinely reflect a real assessment+score pair - a
 * test that wants a freshly compiled row to recompile against uses this instead of assembling
 * one from ResultFactory by hand.
 */
function compiledResult(?User $actor = null): Result
{
    [$assessment, $enrollment] = resultCompilationContext();

    $actor ??= userWithRole(Role::ADMIN);

    return app(ResultService::class)->compile([
        'enrollment_id' => $enrollment->id,
        'class_subject_id' => $assessment->class_subject_id,
        'term_id' => $assessment->term_id,
    ], $actor);
}

/*
|--------------------------------------------------------------------------
| Result approval/publication workflow helpers (Module 13)
|--------------------------------------------------------------------------
|
| Each state is built through the SERVICE's own transition, chained from the one before it,
| rather than assembled directly on ResultFactory - the identical reasoning compiledResult()
| already gives: a test asserting against a SUBMITTED/APPROVED/PUBLISHED/LOCKED result should
| exercise the real path that produces one, including its real enrollment/class-subject/term
| relations, not a shape hand-built to merely look right. The actor for each individual
| transition defaults to an ADMIN (unrestricted, so no teacher assignment is required to reach
| a given state) unless a test names one, matching compiledResult()'s own default.
|
*/

function submittedResult(?User $actor = null): Result
{
    return app(ResultService::class)->submit(compiledResult(), $actor ?? userWithRole(Role::ADMIN));
}

function approvedResult(?User $actor = null): Result
{
    return app(ResultService::class)->approve(submittedResult(), $actor ?? userWithRole(Role::ADMIN));
}

function publishedResult(?User $actor = null): Result
{
    return app(ResultService::class)->publish(approvedResult(), $actor ?? userWithRole(Role::ADMIN));
}

function lockedResult(?User $actor = null): Result
{
    return app(ResultService::class)->lock(publishedResult(), $actor ?? userWithRole(Role::ADMIN));
}

/*
|--------------------------------------------------------------------------
| Report card module helpers (Module 14)
|--------------------------------------------------------------------------
|
| A report card aggregates MULTIPLE Result rows sharing one enrollment and one term - the one
| shape no existing Module 12/13 helper builds, since each of those is deliberately scoped to
| a single, freshly matched class subject. reportCardContext() builds one enrollment and term
| once, then $count distinct class subjects (each with its own assessment, score and Result)
| against that SAME pair, driving each Result through the real compile()/submit()/approve()/
| publish()/lock() pipeline up to $targetStatus - never assembled by hand, so a report-card
| test exercises genuinely authoritative data.
|
*/

/**
 * @return array{0: Enrollment, 1: Term, 2: Collection<int, Result>}
 */
function reportCardContext(
    int $count = 2,
    ResultStatus $targetStatus = ResultStatus::PUBLISHED,
    array $enrollmentAttributes = [],
): array {
    $class = selectableSchoolClass();
    $session = eligibleSession();
    $term = Term::factory()->forSession($session, 1)->create();
    $section = Section::factory()->within($class, 'A', 'A')->create();
    $enrollment = activeEnrollment(array_merge([
        'school_class_id' => $class->id,
        'section_id' => $section->id,
        'academic_session_id' => $session->id,
    ], $enrollmentAttributes));

    $admin = userWithRole(Role::ADMIN);
    $service = app(ResultService::class);

    $results = collect(range(1, $count))->map(function (int $i) use ($class, $term, $enrollment, $admin, $service, $targetStatus): Result {
        $classSubject = activeClassSubject(['school_class_id' => $class->id]);
        $assessment = activeAssessment([
            'class_subject_id' => $classSubject->id,
            'term_id' => $term->id,
            'max_score' => 20,
        ]);

        Score::factory()->forAssessment($assessment)->forEnrollment($enrollment)->create([
            'score' => 10 + $i,
        ]);

        $result = $service->compile([
            'enrollment_id' => $enrollment->id,
            'class_subject_id' => $classSubject->id,
            'term_id' => $term->id,
        ], $admin);

        foreach ([ResultStatus::SUBMITTED, ResultStatus::APPROVED, ResultStatus::PUBLISHED, ResultStatus::LOCKED] as $step) {
            if ($result->status === $targetStatus) {
                break;
            }

            $result = match ($step) {
                ResultStatus::SUBMITTED => $service->submit($result, $admin),
                ResultStatus::APPROVED => $service->approve($result, $admin),
                ResultStatus::PUBLISHED => $service->publish($result, $admin),
                ResultStatus::LOCKED => $service->lock($result, $admin),
                default => $result,
            };
        }

        return $result;
    });

    return [$enrollment, $term, $results];
}

/*
|--------------------------------------------------------------------------
| Promotion module helpers (Module 15)
|--------------------------------------------------------------------------
|
| Chronological ordering matters for almost every promotion test, and
| AcademicSession::factory()'s own default start_date is a random HISTORICAL date, not "now" -
| confirmed directly while smoke-testing this module's own service, the hard way, before any
| test was written. promotionSession() therefore always takes an explicit start/end date rather
| than leaning on the factory default, and promotionContext() builds a source session strictly
| before its target session for exactly this reason.
|
*/

function promotionSession(string $startDate, string $endDate): AcademicSession
{
    return AcademicSession::factory()->create(['start_date' => $startDate, 'end_date' => $endDate]);
}

/**
 * A source enrollment ready to be promoted: one class level holding a source class ("JSS 2")
 * and a target class ("JSS 3") - the brief's own worked example - each with its own section
 * named "A", a source session and a strictly later target session, and an ACTIVE enrollment
 * placing a student in the source class/section for the source session.
 *
 * class_level_id-scoped uniqueness (Module 07's own design) means the literal names/codes
 * below never collide across calls: each call builds a FRESH class level, so "JSS 2"/"JSS 3"
 * are always new rows, never a second attempt at an existing one.
 *
 * @return array{enrollment: Enrollment, sourceClass: SchoolClass, targetClass: SchoolClass, sourceSection: Section, targetSection: Section, sourceSession: AcademicSession, targetSession: AcademicSession}
 */
function promotionContext(array $enrollmentAttributes = []): array
{
    $classLevel = ClassLevel::factory()->create();
    $sourceClass = SchoolClass::factory()->within($classLevel, 'JSS 2', 'JSS2')->create();
    $targetClass = SchoolClass::factory()->within($classLevel, 'JSS 3', 'JSS3')->create();
    $sourceSection = Section::factory()->within($sourceClass, 'A', 'A')->create();
    $targetSection = Section::factory()->within($targetClass, 'A', 'A')->create();

    $sourceSession = promotionSession('2025-09-01', '2026-07-31');
    $targetSession = promotionSession('2026-09-01', '2027-07-31');

    $enrollment = activeEnrollment(array_merge([
        'school_class_id' => $sourceClass->id,
        'section_id' => $sourceSection->id,
        'academic_session_id' => $sourceSession->id,
    ], $enrollmentAttributes));

    return [
        'enrollment' => $enrollment,
        'sourceClass' => $sourceClass,
        'targetClass' => $targetClass,
        'sourceSection' => $sourceSection,
        'targetSection' => $targetSection,
        'sourceSession' => $sourceSession,
        'targetSession' => $targetSession,
    ];
}

/**
 * A valid PROMOTED payload built from a freshly matched promotionContext(). A caller
 * overriding source_enrollment_id/target_academic_session_id is responsible for the
 * consistency of what it overrides - the same posture scoreCreatePayload() takes for its own
 * foreign keys.
 *
 * @param  array{enrollment: Enrollment, sourceClass: SchoolClass, targetClass: SchoolClass, sourceSection: Section, targetSection: Section, sourceSession: AcademicSession, targetSession: AcademicSession}  $context
 */
function promotePayload(array $context, array $overrides = []): array
{
    return array_merge([
        'source_enrollment_id' => $context['enrollment']->id,
        'target_academic_session_id' => $context['targetSession']->id,
        'decision' => PromotionDecision::PROMOTED->value,
        'target_school_class_id' => $context['targetClass']->id,
        'target_section_id' => $context['targetSection']->id,
    ], $overrides);
}

/**
 * A recorded PROMOTED decision, built through the service's own promote() path rather than
 * the factory, so its target enrollment genuinely reflects a real class/section pair - a test
 * that wants a freshly promoted student uses this instead of assembling one from
 * PromotionFactory by hand.
 */
function promotedStudent(?User $actor = null): Promotion
{
    $context = promotionContext();
    $actor ??= userWithRole(Role::ADMIN);

    return app(PromotionService::class)->promote($context['enrollment']->student, [
        'source_enrollment_id' => $context['enrollment']->id,
        'target_academic_session_id' => $context['targetSession']->id,
        'decision' => PromotionDecision::PROMOTED->value,
        'target_school_class_id' => $context['targetClass']->id,
        'target_section_id' => $context['targetSection']->id,
    ], $actor);
}

/*
|--------------------------------------------------------------------------
| Attendance module helpers (Module 17)
|--------------------------------------------------------------------------
|
| One class/section/session plus a handful of ACTIVE enrollments in it - the shape almost
| every attendance test needs, matching promotionContext()'s own "assemble once, override
| what one test is actually about" style.
*/

/**
 * @return array{schoolClass: SchoolClass, section: Section, session: AcademicSession, enrollments: list<Enrollment>}
 */
function attendanceContext(int $studentCount = 3, array $enrollmentAttributes = []): array
{
    $class = selectableSchoolClass();
    $section = Section::factory()->within($class, 'A', 'A')->create();
    $session = eligibleSession();

    $enrollments = collect(range(1, $studentCount))
        ->map(fn (): Enrollment => activeEnrollment(array_merge([
            'school_class_id' => $class->id,
            'section_id' => $section->id,
            'academic_session_id' => $session->id,
        ], $enrollmentAttributes)))
        ->all();

    return [
        'schoolClass' => $class,
        'section' => $section,
        'session' => $session,
        'enrollments' => $enrollments,
    ];
}

/**
 * A valid single-attendance create payload for the first enrollment in a given context.
 *
 * @param  array{schoolClass: SchoolClass, section: Section, session: AcademicSession, enrollments: list<Enrollment>}  $context
 */
function attendancePayload(array $context, array $overrides = []): array
{
    return array_merge([
        'enrollment_id' => $context['enrollments'][0]->id,
        'academic_session_id' => $context['session']->id,
        'school_class_id' => $context['schoolClass']->id,
        'section_id' => $context['section']->id,
        'date' => now()->toDateString(),
        'status' => AttendanceStatus::PRESENT->value,
    ], $overrides);
}

/**
 * A valid bulk-attendance payload: one row per enrollment in the context, all PRESENT unless
 * overridden per row.
 *
 * @param  array{schoolClass: SchoolClass, section: Section, session: AcademicSession, enrollments: list<Enrollment>}  $context
 * @param  array<int, array<string, mixed>>  $rowOverrides  keyed by the enrollment's position in the context
 */
function attendanceBulkPayload(array $context, array $rowOverrides = [], array $overrides = []): array
{
    $attendances = collect($context['enrollments'])->values()->map(function (Enrollment $enrollment, int $index) use ($rowOverrides): array {
        return array_merge([
            'enrollment_id' => $enrollment->id,
            'status' => AttendanceStatus::PRESENT->value,
        ], $rowOverrides[$index] ?? []);
    })->all();

    return array_merge([
        'academic_session_id' => $context['session']->id,
        'school_class_id' => $context['schoolClass']->id,
        'section_id' => $context['section']->id,
        'date' => now()->toDateString(),
        'attendances' => $attendances,
    ], $overrides);
}

/**
 * An actively employed TEACHING staff member assigned to teach some subject in this context's
 * class, for this context's session - eligible under AttendanceService::isAssignedToClass(),
 * without a test having to know which specific class subject makes that true.
 *
 * @param  array{schoolClass: SchoolClass, section: Section, session: AcademicSession, enrollments: list<Enrollment>}  $context
 */
function teacherAssignedToClass(array $context): Staff
{
    $classSubject = activeClassSubject(['school_class_id' => $context['schoolClass']->id]);

    return teacherAssignedTo($classSubject, $context['session']);
}

/**
 * A recorded attendance mark, built through the service's own create() path rather than the
 * factory, so it genuinely reflects a real class/section/session/enrollment combination - a
 * test that wants one already-recorded mark uses this instead of assembling one from
 * AttendanceFactory by hand.
 */
function recordedAttendance(?array $context = null, ?User $actor = null): Attendance
{
    $context ??= attendanceContext(1);
    $actor ??= userWithRole(Role::ADMIN);

    return app(AttendanceService::class)->create(attendancePayload($context), $actor);
}
