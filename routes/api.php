<?php

use App\Http\Controllers\Api\V1\Academic\AcademicContextController;
use App\Http\Controllers\Api\V1\Academic\AcademicSessionController;
use App\Http\Controllers\Api\V1\Academic\ClassLevelController;
use App\Http\Controllers\Api\V1\Academic\SchoolClassController;
use App\Http\Controllers\Api\V1\Academic\SchoolController;
use App\Http\Controllers\Api\V1\Academic\SectionController;
use App\Http\Controllers\Api\V1\Academic\TermController;
use App\Http\Controllers\Api\V1\Admission\AdmissionController;
use App\Http\Controllers\Api\V1\Assessment\AssessmentController;
use App\Http\Controllers\Api\V1\Assessment\AssessmentTypeController;
use App\Http\Controllers\Api\V1\Attendance\AttendanceController;
use App\Http\Controllers\Api\V1\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Api\V1\Auth\NewPasswordController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetLinkController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use App\Http\Controllers\Api\V1\Enrollment\EnrollmentController;
use App\Http\Controllers\Api\V1\Grading\GradingScaleController;
use App\Http\Controllers\Api\V1\Notification\NotificationController;
use App\Http\Controllers\Api\V1\Promotion\PromotionController;
use App\Http\Controllers\Api\V1\ReportCard\ReportCardController;
use App\Http\Controllers\Api\V1\Result\ResultController;
use App\Http\Controllers\Api\V1\ResultChecker\ResultCheckerController;
use App\Http\Controllers\Api\V1\Score\ScoreController;
use App\Http\Controllers\Api\V1\Staff\StaffController;
use App\Http\Controllers\Api\V1\Staff\TeacherAssignmentController;
use App\Http\Controllers\Api\V1\Student\StudentController;
use App\Http\Controllers\Api\V1\Subject\ClassSubjectController;
use App\Http\Controllers\Api\V1\Subject\SubjectController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication routes (v1)
|--------------------------------------------------------------------------
|
| Every route below is served under the /api/v1 prefix configured in
| bootstrap/app.php. Authentication is stateless: requests carry a Sanctum
| bearer token, never a session cookie.
|
*/

Route::prefix('auth')->name('auth.')->group(function (): void {
    /*
     * Public endpoints. "throttle:login" protects credential guessing, and
     * "throttle:auth" additionally throttles password reset link generation.
     */
    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:auth')
        ->name('password.email');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:auth')
        ->name('password.store');

    /*
     * Email verification link.
     *
     * The link is opened in a browser straight from the mailbox, so it cannot carry a
     * bearer token. Authenticity therefore comes from the signed URL plus the SHA-1 hash
     * of the address, exactly as Laravel's own verification route does: no "auth:api"
     * middleware here, and no session either. The "signed" middleware rejects a tampered
     * or expired link, and VerifyEmailController rejects a hash that does not belong to
     * the user id in the URL.
     *
     * Unverified accounts are NOT blocked from logging in: administrators provision
     * accounts by email, so requiring verification would lock out the very first Super
     * Admin. Sensitive endpoints opt in with the "verified" middleware instead.
     */
    Route::get('email/verify/{id}/{hash}', VerifyEmailController::class)
        ->middleware('signed')
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware(['auth:api', 'active'])
        ->name('verification.send');

    /*
     * Authenticated endpoints.
     */
    Route::middleware(['auth:api', 'active'])->group(function (): void {
        Route::get('me', [AuthenticatedSessionController::class, 'me'])->name('me');
        Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    });
});

/*
|--------------------------------------------------------------------------
| School configuration and academic foundation routes (v1)
|--------------------------------------------------------------------------
|
| Module 02. Every route requires a Sanctum bearer token and an active account, and each
| one is additionally gated on a specific permission rather than on a role, so two staff
| members of the same role can hold different rights without inventing another role.
|
| "active" is present on all of them because the school profile's own status is advisory:
| an account that has been suspended must not be able to keep reading the academic
| calendar by presenting a token that was issued before the suspension.
|
| The permissions below are seeded by AcademicPermissionSeeder. They need no code change to
| take effect: the Gate resolves whatever the database says a user holds, and the super
| administrator bypasses the check entirely.
|
*/

Route::middleware(['auth:api', 'active'])->group(function (): void {

    /*
     * The school profile. GET and PUT only: the record is a singleton, so there is nothing
     * to create at a URL of its own and nothing to delete. PUT doubles as the create.
     */
    Route::get('school', [SchoolController::class, 'show'])
        ->middleware('permission:school.view')
        ->name('school.show');

    Route::put('school', [SchoolController::class, 'update'])
        ->middleware('permission:school.update')
        ->name('school.update');

    /*
     * The current year and term, in one read.
     */
    Route::get('academic-context', [AcademicContextController::class, 'show'])
        ->middleware('permission:academic_sessions.view')
        ->name('academic-context.show');

    /*
     * Academic sessions.
     *
     * "activate" is a POST rather than a PATCH because it is a state transition with a side
     * effect beyond the record it names: it completes whichever session was current. It is
     * separated from the ordinary update so a client cannot reach that transition by
     * amending a field, and so it can be granted independently of the ability to rename a
     * session.
     */
    Route::get('academic-sessions', [AcademicSessionController::class, 'index'])
        ->middleware('permission:academic_sessions.view')
        ->name('academic-sessions.index');

    Route::post('academic-sessions', [AcademicSessionController::class, 'store'])
        ->middleware('permission:academic_sessions.create')
        ->name('academic-sessions.store');

    Route::get('academic-sessions/{academicSession}', [AcademicSessionController::class, 'show'])
        ->middleware('permission:academic_sessions.view')
        ->name('academic-sessions.show');

    Route::match(['put', 'patch'], 'academic-sessions/{academicSession}', [AcademicSessionController::class, 'update'])
        ->middleware('permission:academic_sessions.update')
        ->name('academic-sessions.update');

    Route::delete('academic-sessions/{academicSession}', [AcademicSessionController::class, 'destroy'])
        ->middleware('permission:academic_sessions.delete')
        ->name('academic-sessions.destroy');

    Route::post('academic-sessions/{academicSession}/activate', [AcademicSessionController::class, 'activate'])
        ->middleware('permission:academic_sessions.activate')
        ->name('academic-sessions.activate');

    /*
     * Terms.
     *
     * Terms are read under their session, because that is the only question worth asking
     * of a list ("show me this year"), and written through flat /terms/{term} URLs, because
     * changing a term is an action on that term and it carries its own session id.
     */
    Route::get('academic-sessions/{academicSession}/terms', [TermController::class, 'index'])
        ->middleware('permission:terms.view')
        ->name('terms.index');

    Route::post('academic-sessions/{academicSession}/terms', [TermController::class, 'store'])
        ->middleware('permission:terms.create')
        ->name('terms.store');

    Route::get('terms/{term}', [TermController::class, 'show'])
        ->middleware('permission:terms.view')
        ->name('terms.show');

    Route::match(['put', 'patch'], 'terms/{term}', [TermController::class, 'update'])
        ->middleware('permission:terms.update')
        ->name('terms.update');

    Route::delete('terms/{term}', [TermController::class, 'destroy'])
        ->middleware('permission:terms.delete')
        ->name('terms.destroy');

    Route::post('terms/{term}/activate', [TermController::class, 'activate'])
        ->middleware('permission:terms.activate')
        ->name('terms.activate');

    /*
     * Class levels, classes and sections.
     *
     * No activate endpoint for any of the three: none of them is a singleton, so "the
     * current one" does not apply and there is no transition to isolate. Retiring a record
     * is a status value, amended through the ordinary update.
     */
    Route::get('class-levels', [ClassLevelController::class, 'index'])
        ->middleware('permission:class_levels.view')
        ->name('class-levels.index');

    Route::post('class-levels', [ClassLevelController::class, 'store'])
        ->middleware('permission:class_levels.create')
        ->name('class-levels.store');

    Route::get('class-levels/{classLevel}', [ClassLevelController::class, 'show'])
        ->middleware('permission:class_levels.view')
        ->name('class-levels.show');

    Route::match(['put', 'patch'], 'class-levels/{classLevel}', [ClassLevelController::class, 'update'])
        ->middleware('permission:class_levels.update')
        ->name('class-levels.update');

    Route::delete('class-levels/{classLevel}', [ClassLevelController::class, 'destroy'])
        ->middleware('permission:class_levels.delete')
        ->name('class-levels.destroy');

    Route::get('classes', [SchoolClassController::class, 'index'])
        ->middleware('permission:classes.view')
        ->name('classes.index');

    Route::post('classes', [SchoolClassController::class, 'store'])
        ->middleware('permission:classes.create')
        ->name('classes.store');

    Route::get('classes/{schoolClass}', [SchoolClassController::class, 'show'])
        ->middleware('permission:classes.view')
        ->name('classes.show');

    Route::match(['put', 'patch'], 'classes/{schoolClass}', [SchoolClassController::class, 'update'])
        ->middleware('permission:classes.update')
        ->name('classes.update');

    Route::delete('classes/{schoolClass}', [SchoolClassController::class, 'destroy'])
        ->middleware('permission:classes.delete')
        ->name('classes.destroy');

    Route::get('sections', [SectionController::class, 'index'])
        ->middleware('permission:sections.view')
        ->name('sections.index');

    Route::post('sections', [SectionController::class, 'store'])
        ->middleware('permission:sections.create')
        ->name('sections.store');

    Route::get('sections/{section}', [SectionController::class, 'show'])
        ->middleware('permission:sections.view')
        ->name('sections.show');

    Route::match(['put', 'patch'], 'sections/{section}', [SectionController::class, 'update'])
        ->middleware('permission:sections.update')
        ->name('sections.update');

    Route::delete('sections/{section}', [SectionController::class, 'destroy'])
        ->middleware('permission:sections.delete')
        ->name('sections.destroy');

    /*
     * Subjects & Class Subjects (Module 07)
     */
    Route::get('subjects', [SubjectController::class, 'index'])
        ->middleware('permission:subjects.view')
        ->name('subjects.index');

    Route::post('subjects', [SubjectController::class, 'store'])
        ->middleware('permission:subjects.create')
        ->name('subjects.store');

    Route::get('subjects/{subject}', [SubjectController::class, 'show'])
        ->middleware('permission:subjects.view')
        ->name('subjects.show');

    Route::match(['put', 'patch'], 'subjects/{subject}', [SubjectController::class, 'update'])
        ->middleware('permission:subjects.update')
        ->name('subjects.update');

    Route::delete('subjects/{subject}', [SubjectController::class, 'destroy'])
        ->middleware('permission:subjects.delete')
        ->name('subjects.destroy');

    Route::get('class-subjects', [ClassSubjectController::class, 'index'])
        ->middleware('permission:class_subjects.view')
        ->name('class-subjects.index');

    Route::post('class-subjects', [ClassSubjectController::class, 'store'])
        ->middleware('permission:class_subjects.create')
        ->name('class-subjects.store');

    Route::get('class-subjects/{classSubject}', [ClassSubjectController::class, 'show'])
        ->middleware('permission:class_subjects.view')
        ->name('class-subjects.show');

    Route::match(['put', 'patch'], 'class-subjects/{classSubject}', [ClassSubjectController::class, 'update'])
        ->middleware('permission:class_subjects.update')
        ->name('class-subjects.update');

});

/*
|--------------------------------------------------------------------------
| Staff management routes (v1)
|--------------------------------------------------------------------------
|
| Module 03. Same shape as Module 02: a Sanctum bearer token, an active account, and one
| permission per route. The permissions are seeded by StaffPermissionSeeder and need no
| code change to take effect.
|
| Three deliberate differences from the academic routes above:
|
|  - NO delete endpoint. A staff record is kept; employment ends by being deactivated or
|    terminated, and the history stays. There is therefore no staff.delete permission either,
|    because a permission for an operation that cannot be performed has no meaning. When
|    subjects, attendance and results arrive, their own restrictOnDelete keys become the
|    real guard - and by then a guard that can actually fail is one worth having.
|
|  - PUT alone, not Route::match(['put','patch']). The amend is a whole-record write, and
|    one verb is one fewer thing for a client to try and mis-use. A PATCH is answered with
|    405 and an Allow header naming the supported methods, which is a clearer answer than
|    silently behaving like a partial write. This is a conscious divergence from the
|    academic routes; change it to Route::match here if consistency is preferred.
|
|  - activate and deactivate are POSTs, for Module 02's reason: an employment transition is
|    an action, not a field, and it is separated so the ability to end someone's employment
|    can be granted without the ability to edit a staff record. Registrar holds
|    staff.update but deliberately not staff.deactivate - see StaffPermissionSeeder.
|
*/

Route::middleware(['auth:api', 'active'])->prefix('staff')->name('staff.')->group(function (): void {
    Route::get('/', [StaffController::class, 'index'])
        ->middleware('permission:staff.view')
        ->name('index');

    Route::post('/', [StaffController::class, 'store'])
        ->middleware('permission:staff.create')
        ->name('store');

    Route::get('{staff}', [StaffController::class, 'show'])
        ->middleware('permission:staff.view')
        ->name('show');

    Route::put('{staff}', [StaffController::class, 'update'])
        ->middleware('permission:staff.update')
        ->name('update');

    Route::post('{staff}/activate', [StaffController::class, 'activate'])
        ->middleware('permission:staff.activate')
        ->name('activate');

    Route::post('{staff}/deactivate', [StaffController::class, 'deactivate'])
        ->middleware('permission:staff.deactivate')
        ->name('deactivate');
});

/*
|--------------------------------------------------------------------------
| Student management routes (v1)
|--------------------------------------------------------------------------
|
| Module 04. Four routes: the roll can be read, a pupil added, a pupil read, and a pupil
| amended. Everything else about the roll belongs to a module that has not been written yet.
|
| The permissions are seeded by StudentPermissionSeeder and need no code change to take
| effect. They are PLURAL - students.view, students.create, students.update - following the
| majority convention in this project (school.view, classes.*, terms.*) and matching the
| "students.view" that Module 01 already used as its worked example. Module 03's staff.* is
| the outlier and is left alone; see the audit's naming note.
|
| Four deliberate differences from the modules above, and each one is a refusal rather than
| an omission:
|
|  - NO delete endpoint, and no students.delete permission. A pupil is a child. They do not
|    leave the school by being erased from it, they leave by being marked WITHDRAWN or
|    GRADUATED, and the record stays afterwards. Unlike Module 03, where the guard was "no
|    dependents yet", the reasoning here holds no matter what arrives later.
|
|  - NO activate/deactivate endpoints, unlike staff. A pupil's lifecycle is one orthogonal
|    question and it is amended through the same PUT as the name. Two extra routes and two
|    extra permissions to correct a typo in a surname is a bad trade.
|
|  - PUT alone, following Module 03 rather than the academic routes' Route::match. The
|    amend is a whole-record write, and one verb is one fewer thing for a client to try and
|    mis-use.
|
|  - NO class, section or session filters on the index. A pupil's placement is an enrollment
|    fact belonging to a future module, and the honest way to answer "who is in JSS 2 this
|    session" is GET /api/v1/classes/{class}/students, not a filter over a roll that has no
|    column to filter on.
|
| Note what creating a pupil does NOT do: it does not create a login. There is no
| email/password input and no account is provisioned, so a pupil created here has
| account_status = null until the portal module links one. Pupils outnumber staff and
| identity does not require a login, so making an account a precondition of recording a
| child would have made this module unusable for exactly the pupils most likely to need it.
|
*/

Route::middleware(['auth:api', 'active'])->prefix('students')->name('students.')->group(function (): void {
    Route::get('/', [StudentController::class, 'index'])
        ->middleware('permission:students.view')
        ->name('index');

    Route::post('/', [StudentController::class, 'store'])
        ->middleware('permission:students.create')
        ->name('store');

    Route::get('{student}', [StudentController::class, 'show'])
        ->middleware('permission:students.view')
        ->name('show');

    Route::put('{student}', [StudentController::class, 'update'])
        ->middleware('permission:students.update')
        ->name('update');
});

/*
|--------------------------------------------------------------------------
| Admission management routes (v1)
|--------------------------------------------------------------------------
|
| Module 05. An admission is the school's decision record for one applicant, targeting one
| academic session - not a pupil, and not an enrollment. See the admissions migration and
| AdmissionService for the full reasoning.
|
| The permissions are seeded by AdmissionPermissionSeeder and need no code change to take
| effect.
|
| NO delete endpoint and no admissions.delete permission, for the same reason Module 03 and
| Module 04 have none: an admission is a historical business record of a decision, not
| something the school erases because it is old.
|
| Three dedicated workflow endpoints - admit, reject, withdraw - rather than reaching the
| status through PUT. Each is a state transition with a side effect beyond the record it
| names (admit's is a created Student), so each is separated exactly as Module 03 separated
| staff.activate/staff.deactivate from the ordinary amend, and each is gated on its own
| permission so it can be granted independently of admissions.update.
*/

Route::middleware(['auth:api', 'active'])->prefix('admissions')->name('admissions.')->group(function (): void {
    Route::get('/', [AdmissionController::class, 'index'])
        ->middleware('permission:admissions.view')
        ->name('index');

    Route::post('/', [AdmissionController::class, 'store'])
        ->middleware('permission:admissions.create')
        ->name('store');

    Route::get('{admission}', [AdmissionController::class, 'show'])
        ->middleware('permission:admissions.view')
        ->name('show');

    Route::put('{admission}', [AdmissionController::class, 'update'])
        ->middleware('permission:admissions.update')
        ->name('update');

    Route::post('{admission}/admit', [AdmissionController::class, 'admit'])
        ->middleware('permission:admissions.admit')
        ->name('admit');

    Route::post('{admission}/reject', [AdmissionController::class, 'reject'])
        ->middleware('permission:admissions.reject')
        ->name('reject');

    Route::post('{admission}/withdraw', [AdmissionController::class, 'withdraw'])
        ->middleware('permission:admissions.withdraw')
        ->name('withdraw');
});

/*
|--------------------------------------------------------------------------
| Student enrollment routes (v1)
|--------------------------------------------------------------------------
|
| Module 06. An enrollment is the authoritative academic placement - student, session, class
| and section - for one academic session. It is not the student's identity (Module 04) and
| not the decision that may have preceded it (Module 05); see the enrollments migration.
|
| The permissions are seeded by EnrollmentPermissionSeeder and need no code change to take
| effect.
|
| NO delete endpoint and no enrollments.delete permission, for the same reason Module 03, 04
| and 05 have none - stronger here, because results, attendance, promotion and report cards
| are all expected to reference this row. `cancel` is the record-preserving replacement.
|
| Two dedicated workflow endpoints - withdraw, cancel - rather than reaching status through
| PUT, following Module 05's admit/reject/withdraw pattern: each is a state transition with a
| permanent effect, so each is gated on its own permission, independent of enrollments.update.
|
| PUT touches only enrollment_date and notes. There is no route or field through which the
| placement itself (student_id, academic_session_id, school_class_id, section_id) can be
| changed once created - a transfer/class-change operation is explicitly out of this module's
| scope; see the Module 06 audit.
*/

Route::middleware(['auth:api', 'active'])->prefix('enrollments')->name('enrollments.')->group(function (): void {
    Route::get('/', [EnrollmentController::class, 'index'])
        ->middleware('permission:enrollments.view')
        ->name('index');

    Route::post('/', [EnrollmentController::class, 'store'])
        ->middleware('permission:enrollments.create')
        ->name('store');

    Route::get('{enrollment}', [EnrollmentController::class, 'show'])
        ->middleware('permission:enrollments.view')
        ->name('show');

    Route::put('{enrollment}', [EnrollmentController::class, 'update'])
        ->middleware('permission:enrollments.update')
        ->name('update');

    Route::post('{enrollment}/withdraw', [EnrollmentController::class, 'withdraw'])
        ->middleware('permission:enrollments.withdraw')
        ->name('withdraw');

    Route::post('{enrollment}/cancel', [EnrollmentController::class, 'cancel'])
        ->middleware('permission:enrollments.cancel')
        ->name('cancel');
});

/*
|--------------------------------------------------------------------------
| Subject catalogue and class-subject routes (v1)
|--------------------------------------------------------------------------
|
| Module 07. A subject (Mathematics, Biology) is a reusable catalogue entry; a class subject
| (JSS 2 -> Mathematics) is one class's offering of it. Neither carries a teacher, an
| assessment or a score - see the subjects and class_subjects migrations. Teacher assignment
| is a future module built on Staff/StaffType, not on a new Teacher entity.
|
| The permissions are seeded by SubjectPermissionSeeder and need no code change to take
| effect.
|
| subjects.* has a DELETE endpoint, matching class_levels.*, classes.* and sections.* - a
| subject is a catalogue entry, the same family as those three, guarded against removing one
| that any class still offers.
|
| class_subjects.* has NO delete endpoint. It is the anchor a future teacher assignment and a
| future assessment will reference, the identical posture Module 06 takes toward enrollments.
| "Removing a subject from a class" is status: INACTIVE through the ordinary update, not a
| delete and not a dedicated workflow endpoint either - unlike ending an admission or an
| enrollment, deactivating an offering is a freely reversible toggle with no side effect
| beyond the record itself.
*/

Route::middleware(['auth:api', 'active'])->prefix('subjects')->name('subjects.')->group(function (): void {
    Route::get('/', [SubjectController::class, 'index'])
        ->middleware('permission:subjects.view')
        ->name('index');

    Route::post('/', [SubjectController::class, 'store'])
        ->middleware('permission:subjects.create')
        ->name('store');

    Route::get('{subject}', [SubjectController::class, 'show'])
        ->middleware('permission:subjects.view')
        ->name('show');

    Route::put('{subject}', [SubjectController::class, 'update'])
        ->middleware('permission:subjects.update')
        ->name('update');

    Route::delete('{subject}', [SubjectController::class, 'destroy'])
        ->middleware('permission:subjects.delete')
        ->name('destroy');
});

Route::middleware(['auth:api', 'active'])->prefix('class-subjects')->name('class-subjects.')->group(function (): void {
    Route::get('/', [ClassSubjectController::class, 'index'])
        ->middleware('permission:class_subjects.view')
        ->name('index');

    Route::post('/', [ClassSubjectController::class, 'store'])
        ->middleware('permission:class_subjects.create')
        ->name('store');

    Route::get('{classSubject}', [ClassSubjectController::class, 'show'])
        ->middleware('permission:class_subjects.view')
        ->name('show');

    Route::put('{classSubject}', [ClassSubjectController::class, 'update'])
        ->middleware('permission:class_subjects.update')
        ->name('update');
});

/*
|--------------------------------------------------------------------------
| Teacher assignment routes (v1)
|--------------------------------------------------------------------------
|
| Module 08. An assignment names which teaching staff member (Staff whose staff_type is
| TEACHING) is responsible for a class subject, for one academic session - session-scoped,
| unlike class_subjects, because who teaches a standing curriculum offering genuinely changes
| year to year. See the teacher_assignments migration.
|
| The permissions are seeded by TeacherAssignmentPermissionSeeder and need no code change to
| take effect. REGISTRAR holds only teacher_assignments.view here, a deliberate departure from
| the full CRUD Modules 05-07 grant REGISTRAR: RoleSeeder does not name staffing assignment
| among a registrar's duties the way it names admissions and enrollment.
|
| NO delete endpoint and no teacher_assignments.delete permission, for the same reason
| Modules 05-07 have none for their own historical anchor records - stronger here, because a
| future assessment/score/result chain will reference exactly this row to answer "who taught
| this". `cancel` is the record-preserving replacement.
|
| Two dedicated workflow endpoints - end, cancel - rather than reaching status through PUT,
| following Module 06's withdraw/cancel pattern: each is a one-shot state transition, so each
| is gated on its own permission, independent of teacher_assignments.update.
|
| PUT touches only notes. There is no route or field through which the assignment itself
| (teaching_staff_id, class_subject_id, academic_session_id) can be changed once created -
| reassignment is end() the current one, then POST a new one, composing two primitives rather
| than a third "reassign" operation this module does not build.
*/

Route::middleware(['auth:api', 'active'])->prefix('teacher-assignments')->name('teacher-assignments.')->group(function (): void {
    Route::get('/', [TeacherAssignmentController::class, 'index'])
        ->middleware('permission:teacher_assignments.view')
        ->name('index');

    Route::post('/', [TeacherAssignmentController::class, 'store'])
        ->middleware('permission:teacher_assignments.create')
        ->name('store');

    Route::get('{teacherAssignment}', [TeacherAssignmentController::class, 'show'])
        ->middleware('permission:teacher_assignments.view')
        ->name('show');

    Route::put('{teacherAssignment}', [TeacherAssignmentController::class, 'update'])
        ->middleware('permission:teacher_assignments.update')
        ->name('update');

    Route::post('{teacherAssignment}/end', [TeacherAssignmentController::class, 'end'])
        ->middleware('permission:teacher_assignments.end')
        ->name('end');

    Route::post('{teacherAssignment}/cancel', [TeacherAssignmentController::class, 'cancel'])
        ->middleware('permission:teacher_assignments.cancel')
        ->name('cancel');
});

/*
|--------------------------------------------------------------------------
| Assessment configuration
|--------------------------------------------------------------------------
|
| Module 09. An assessment type is a reusable category (CA, Test, Examination); an assessment
| is one configured instance of a category against a class subject and a term, e.g.
| "Mathematics - First Term - JSS 2 - CA 1". Neither carries a score, a grade or a result -
| see the assessments migration. Recording and compiling scores is a future module built on
| THIS table's id, not on a new entity.
|
| assessment_types.* has a DELETE endpoint, matching subjects.*, class_levels.*, classes.*
| and sections.* - a category is a catalogue entry, the same family as those, guarded against
| removing one that any assessment still uses.
|
| assessments.* has NO delete endpoint. It is the anchor a future score will reference, the
| identical posture Module 06, Module 07 and Module 08 take toward their own anchor records.
| "Retiring" a mistakenly configured assessment is status: INACTIVE through the ordinary
| update, not a delete.
*/

Route::middleware(['auth:api', 'active'])->prefix('assessment-types')->name('assessment-types.')->group(function (): void {
    Route::get('/', [AssessmentTypeController::class, 'index'])
        ->middleware('permission:assessment_types.view')
        ->name('index');

    Route::post('/', [AssessmentTypeController::class, 'store'])
        ->middleware('permission:assessment_types.create')
        ->name('store');

    Route::get('{assessmentType}', [AssessmentTypeController::class, 'show'])
        ->middleware('permission:assessment_types.view')
        ->name('show');

    Route::put('{assessmentType}', [AssessmentTypeController::class, 'update'])
        ->middleware('permission:assessment_types.update')
        ->name('update');

    Route::delete('{assessmentType}', [AssessmentTypeController::class, 'destroy'])
        ->middleware('permission:assessment_types.delete')
        ->name('destroy');
});

Route::middleware(['auth:api', 'active'])->prefix('assessments')->name('assessments.')->group(function (): void {
    Route::get('/', [AssessmentController::class, 'index'])
        ->middleware('permission:assessments.view')
        ->name('index');

    Route::post('/', [AssessmentController::class, 'store'])
        ->middleware('permission:assessments.create')
        ->name('store');

    Route::get('{assessment}', [AssessmentController::class, 'show'])
        ->middleware('permission:assessments.view')
        ->name('show');

    Route::put('{assessment}', [AssessmentController::class, 'update'])
        ->middleware('permission:assessments.update')
        ->name('update');
});

/*
|--------------------------------------------------------------------------
| Score management
|--------------------------------------------------------------------------
|
| Module 10. What a specific student obtained against a specific configured assessment -
| "Student 123, Mathematics CA 1, 17". References assessment_id + enrollment_id, NOT
| student_id: a score belongs to the student's authoritative placement for the session the
| assessment falls in, not merely to the person. See the scores migration.
|
| scores.* has NO delete endpoint. A score is the anchor a future grading/result module will
| reference, the identical posture Module 06 through Module 09 take toward their own anchor
| records. A mistaken mark is corrected through the ordinary PUT, not erased.
|
| Every read and write is ADDITIONALLY scoped by ScoreService to the acting user, on top of
| this permission gate: a holder of scores.create is not thereby entitled to enter a score for
| every class subject in the school if they are teaching staff - see ScoreService's own
| docblock for the assignment-scoped check this project's flat permission model cannot express
| on its own.
|
| POST /scores/bulk shares scores.create rather than a separate permission: recording many
| students' marks for one assessment in one call is the same capability as recording one,
| performed at the shape a class roster is actually entered in.
*/

Route::middleware(['auth:api', 'active'])->prefix('scores')->name('scores.')->group(function (): void {
    Route::get('/', [ScoreController::class, 'index'])
        ->middleware('permission:scores.view')
        ->name('index');

    Route::post('/', [ScoreController::class, 'store'])
        ->middleware('permission:scores.create')
        ->name('store');

    Route::post('bulk', [ScoreController::class, 'bulkStore'])
        ->middleware('permission:scores.create')
        ->name('bulk-store');

    Route::get('{score}', [ScoreController::class, 'show'])
        ->middleware('permission:scores.view')
        ->name('show');

    Route::put('{score}', [ScoreController::class, 'update'])
        ->middleware('permission:scores.update')
        ->name('update');
});

/*
|--------------------------------------------------------------------------
| Grading
|--------------------------------------------------------------------------
|
| Module 11. A grading scale is a reusable scheme scoped to one class level ("Junior
| Secondary Standard"), made up of percentage bands ("70 to 100 -> A, grade point 5,
| Excellent"). This module interprets a percentage as a grade; it does not compile a
| subject/term result, calculate a weighted total, or publish anything - see the
| grading_scales and grading_scale_items migrations.
|
| grading_scales.* has NO delete endpoint. A scale is the anchor a future grading/result
| compilation module will reference to interpret a historical result, the identical posture
| Module 06 through Module 10 take toward their own anchor records. Retiring one is
| status: INACTIVE/ARCHIVED through the ordinary update, not a delete.
|
| POST /grading-scales/{id}/calculate is a read-only preview - percentage in, grade
| information out - gated on grading_scales.view rather than a new permission, since it
| mutates nothing.
*/

Route::middleware(['auth:api', 'active'])->prefix('grading-scales')->name('grading-scales.')->group(function (): void {
    Route::get('/', [GradingScaleController::class, 'index'])
        ->middleware('permission:grading_scales.view')
        ->name('index');

    Route::post('/', [GradingScaleController::class, 'store'])
        ->middleware('permission:grading_scales.create')
        ->name('store');

    Route::get('{gradingScale}', [GradingScaleController::class, 'show'])
        ->middleware('permission:grading_scales.view')
        ->name('show');

    Route::put('{gradingScale}', [GradingScaleController::class, 'update'])
        ->middleware('permission:grading_scales.update')
        ->name('update');

    Route::post('{gradingScale}/calculate', [GradingScaleController::class, 'calculate'])
        ->middleware('permission:grading_scales.view')
        ->name('calculate');
});

/*
|--------------------------------------------------------------------------
| Result compilation, approval and publication
|--------------------------------------------------------------------------
|
| Module 12. The compiled academic outcome for one student's enrollment, in one class
| subject, for one term - raw Assessment Scores (Module 10) transformed into a percentage,
| and (once every configured assessment has a score) a grade, grade point and remark through
| Module 11's grading scale. See the results migration for why this is one table, not a
| parent Result plus child ResultItem rows.
|
| results.* has NO delete endpoint and no plain store()/update() - a result is written only
| through compile()/bulk and the four workflow actions below, never a raw create or amend of
| client-supplied values. Recompiling (the identical compile operation, run again after a
| score correction) is how a COMPILED-or-earlier result changes; once submitted, it can only
| move forward through the workflow - see ResultService::persist()/isRecompilable().
|
| POST /results/bulk shares results.compile rather than a separate permission: compiling a
| whole class subject's results in one call is the same capability as compiling one student's.
|
| Module 13 adds the linear workflow COMPILED -> SUBMITTED -> APPROVED -> PUBLISHED -> LOCKED,
| as four POST .../{action} routes - the identical shape Module 06's enrollments/{id}/withdraw
| and Module 08's teacher-assignments/{id}/end|cancel already use for a state transition, each
| gated on its OWN permission rather than reusing results.compile. None accepts a request body:
| the server alone determines the next state, never a client-supplied "status".
*/

Route::middleware(['auth:api', 'active'])->prefix('results')->name('results.')->group(function (): void {
    Route::get('/', [ResultController::class, 'index'])
        ->middleware('permission:results.view')
        ->name('index');

    Route::post('compile', [ResultController::class, 'compile'])
        ->middleware('permission:results.compile')
        ->name('compile');

    Route::post('bulk', [ResultController::class, 'bulkCompile'])
        ->middleware('permission:results.compile')
        ->name('bulk-compile');

    Route::get('{result}', [ResultController::class, 'show'])
        ->middleware('permission:results.view')
        ->name('show');

    Route::post('{result}/submit', [ResultController::class, 'submit'])
        ->middleware('permission:results.submit')
        ->name('submit');

    Route::post('{result}/approve', [ResultController::class, 'approve'])
        ->middleware('permission:results.approve')
        ->name('approve');

    Route::post('{result}/publish', [ResultController::class, 'publish'])
        ->middleware('permission:results.publish')
        ->name('publish');

    Route::post('{result}/lock', [ResultController::class, 'lock'])
        ->middleware('permission:results.lock')
        ->name('lock');
});

/*
|--------------------------------------------------------------------------
| Report cards
|--------------------------------------------------------------------------
|
| Module 14. A read-only presentation of a student's finalized subject results for one
| enrollment and one term - never a second calculation of them. Every figure rendered here is
| read directly from Module 12's Result rows exactly as Module 13 left them; see
| ReportCardService's own docblock for the full reasoning, and why only PUBLISHED and LOCKED
| results ever appear.
|
| Two GET routes only - report_cards.view gates both. No POST/PUT/DELETE exists: a report card
| has no lifecycle of its own to mutate.
|
| /report-cards/enrollments/{enrollment}/terms/{term} reuses two EXISTING identifiers -
| enrollment_id and term_id, the same pair results.enrollment_id/results.term_id already key
| on - rather than inventing a third "report card id" or keying off student_id/session_id the
| way a student's identity alone never safely identifies a placement (the same reasoning every
| module since Module 06 already applies).
*/

Route::middleware(['auth:api', 'active'])->prefix('report-cards')->name('report-cards.')->group(function (): void {
    Route::get('enrollments/{enrollment}/terms/{term}', [ReportCardController::class, 'show'])
        ->middleware('permission:report_cards.view')
        ->name('show');

    Route::get('students/{student}', [ReportCardController::class, 'forStudent'])
        ->middleware('permission:report_cards.view')
        ->name('for-student');
});

/*
|--------------------------------------------------------------------------
| Student promotion
|--------------------------------------------------------------------------
|
| Module 15. Recording what happens to a student's placement going into a new academic
| session - PROMOTED, RETAINED, GRADUATED or NOT_ELIGIBLE - never a mutation of the source
| enrollment. See the promotions migration and PromotionService for the full reasoning.
|
| POST /students/{student}/promote lives alongside GET/PUT /students/{student} (Module 04)
| rather than inside that module's own route group, because it is owned by a different
| controller and permission - the identical "same URL family, separate ownership" shape
| results/{result}/submit|approve|... (Module 13) already establishes for a route that reads
| like it belongs to one resource but is gated and served by another module entirely.
|
| No destroy(), no update(): a promotion decision is a one-shot historical fact. No bulk
| endpoint either - see the Module 15 audit for why bulk promotion is deliberately deferred.
*/

Route::middleware(['auth:api', 'active'])->group(function (): void {
    Route::post('students/{student}/promote', [PromotionController::class, 'store'])
        ->middleware('permission:promotions.create')
        ->name('students.promote');

    Route::prefix('promotions')->name('promotions.')->group(function (): void {
        Route::get('/', [PromotionController::class, 'index'])
            ->middleware('permission:promotions.view')
            ->name('index');

        Route::get('{promotion}', [PromotionController::class, 'show'])
            ->middleware('permission:promotions.view')
            ->name('show');
    });
});

/*
|--------------------------------------------------------------------------
| Result checker
|--------------------------------------------------------------------------
|
| Module 16. A public, unauthenticated way to retrieve a student's already-PUBLISHED (or
| LOCKED) result, for a parent or guardian with no portal login - the audience Module 14's own
| report-card docs already anticipated this module for. No auth:api/active middleware and no
| permission gate: the credential is the request body (student_number + date_of_birth), not a
| bearer token. "throttle:result-checker" is this endpoint's own brute-force protection, the
| same technique "throttle:login" already gives credential guessing above.
|
| Reuses ReportCardService::forEnrollmentAndTermUnguarded() (Module 14) and ReportCardResource
| unchanged - never a second result-calculation engine or a second presentation format.
*/

Route::post('result-checker', [ResultCheckerController::class, 'check'])
    ->middleware('throttle:result-checker')
    ->name('result-checker.check');

/*
|--------------------------------------------------------------------------
| Attendance
|--------------------------------------------------------------------------
|
| Module 17. Whether a specific student was present, absent, late or excused on a specific
| date, against a specific enrollment - never a mutable column on Student or Enrollment. See
| the attendances migration for the full reasoning, in particular why academic_session_id/
| school_class_id/section_id are copied from the enrollment rather than purely derived.
|
| One filterable GET /attendance list endpoint serves class-register reads, student history
| and date-range queries alike, rather than a separate route per angle - the identical
| "one endpoint, many filters" shape Module 10's own scores.* already established.
|
| GET /attendance/summary and POST /attendance/bulk are both registered BEFORE
| GET|PUT /attendance/{attendance} so neither literal segment is swallowed by the wildcard.
|
| No destroy(): attendance is academic history, the same posture every anchor table since
| Module 06 already takes. A mistaken mark is corrected through PUT, not erased.
*/

Route::middleware(['auth:api', 'active'])->prefix('attendance')->name('attendance.')->group(function (): void {
    Route::get('/', [AttendanceController::class, 'index'])
        ->middleware('permission:attendance.view')
        ->name('index');

    Route::post('/', [AttendanceController::class, 'store'])
        ->middleware('permission:attendance.record')
        ->name('store');

    Route::post('bulk', [AttendanceController::class, 'bulkStore'])
        ->middleware('permission:attendance.record')
        ->name('bulk-store');

    Route::get('summary', [AttendanceController::class, 'summary'])
        ->middleware('permission:attendance.view')
        ->name('summary');

    Route::get('{attendance}', [AttendanceController::class, 'show'])
        ->middleware('permission:attendance.view')
        ->name('show');

    Route::put('{attendance}', [AttendanceController::class, 'update'])
        ->middleware('permission:attendance.update')
        ->name('update');
});

/*
|--------------------------------------------------------------------------
| Notifications
|--------------------------------------------------------------------------
|
| A user's own database notifications - the underlying infrastructure a future module can
| write to via `$user->notify(new SomeNotification(...))` without ever touching this route
| file or this controller. Today's senders: StaffService::create() (Module 03),
| EnrollmentService::create() (Module 06) and ResultService::publish() (Module 13) - see each
| notification class's own docblock for its exact trigger and recipient.
|
| Gated on auth:api/active only, matching GET /auth/me - a notification is intrinsically
| scoped to the account it was sent to, so there is no separate resource-level permission to
| grant or withhold; NotificationService itself refuses cross-user access.
|
| GET /notifications/unread is registered BEFORE POST /notifications/{notification}/read so
| the literal segment "unread" is never swallowed by the {notification} route parameter.
*/

Route::middleware(['auth:api', 'active'])->prefix('notifications')->name('notifications.')->group(function (): void {
    Route::get('/', [NotificationController::class, 'index'])->name('index');
    Route::get('unread', [NotificationController::class, 'unread'])->name('unread');
    Route::post('read-all', [NotificationController::class, 'markAllAsRead'])->name('read-all');
    Route::post('{notification}/read', [NotificationController::class, 'markAsRead'])->name('read');
});
