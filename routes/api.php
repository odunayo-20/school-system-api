<?php

use App\Http\Controllers\Api\V1\Academic\AcademicContextController;
use App\Http\Controllers\Api\V1\Academic\AcademicSessionController;
use App\Http\Controllers\Api\V1\Academic\ClassLevelController;
use App\Http\Controllers\Api\V1\Academic\SchoolClassController;
use App\Http\Controllers\Api\V1\Academic\SchoolController;
use App\Http\Controllers\Api\V1\Academic\SectionController;
use App\Http\Controllers\Api\V1\Academic\TermController;
use App\Http\Controllers\Api\V1\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Api\V1\Auth\NewPasswordController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetLinkController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
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
});
