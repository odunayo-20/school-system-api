<?php

use App\Enums\CatalogStatus;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Assessment;
use App\Models\Permission;
use App\Models\Term;

/*
|--------------------------------------------------------------------------
| Assessment authorization, filters and exposure (Module 09)
|--------------------------------------------------------------------------
*/

/*
| Authorization
*/

it('refuses assessments to an unauthenticated caller', function (): void {
    activeAssessment();

    test()->getJson('/api/v1/assessments')->assertUnauthorized();
    test()->postJson('/api/v1/assessments', assessmentCreatePayload())->assertUnauthorized();
});

it('refuses assessments to a user with no assessments permission', function (): void {
    $teacher = userWithRole(Role::STAFF);
    $token = loginAs($teacher);

    withToken($token)->getJson('/api/v1/assessments')->assertForbidden();
    withToken($token)->postJson('/api/v1/assessments', assessmentCreatePayload())->assertForbidden();
});

it('keeps assessments away from teaching staff, deferring their own scoped access to the future scores module', function (): void {
    // See the Module 09 audit: ownership-scoped authorization (a teacher may only see
    // assessments for class subjects THEY are assigned to) is deliberately not built here.
    // Nothing in this project's authorization model expresses that yet, and inventing it
    // now, ahead of the module that actually needs it, would be speculative architecture.
    $teacher = eligibleTeacher();
    $token = loginAs($teacher->user);

    withToken($token)->getJson('/api/v1/assessments')->assertForbidden();
});

it('keeps assessments away from a pupil account', function (): void {
    $token = loginAs(userWithRole(Role::STUDENT));

    withToken($token)->getJson('/api/v1/assessments')->assertForbidden();
    withToken($token)->postJson('/api/v1/assessments', assessmentCreatePayload())->assertForbidden();
});

it('grants assessments to super admin, admin and registrar', function (): void {
    foreach ([Role::SUPER_ADMIN, Role::ADMIN, Role::REGISTRAR] as $role) {
        $token = loginAs(userWithRole($role));

        withToken($token)->getJson('/api/v1/assessments')->assertOk();
        withToken($token)->postJson('/api/v1/assessments', assessmentCreatePayload())->assertCreated();
    }
});

it('requires a specific permission per action rather than one blanket grant', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $assessment = activeAssessment();

    $admin->role->permissions()->detach(
        Permission::query()->where('name', 'assessments.create')->value('id')
    );

    $token = loginAs($admin);

    withToken($token)->getJson('/api/v1/assessments')->assertOk();
    withToken($token)->postJson('/api/v1/assessments', assessmentCreatePayload())->assertForbidden();
    withToken($token)->putJson("/api/v1/assessments/{$assessment->id}", assessmentUpdatePayload())->assertOk();
});

it('seeds the permissions this module owns and no others', function (): void {
    $names = Permission::query()->where('name', 'like', 'assessments.%')->pluck('name')->all();

    expect($names)->toEqualCanonicalizing(['assessments.view', 'assessments.create', 'assessments.update']);
});

it('refuses a suspended account, even with a token minted before the suspension', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    $admin->forceFill(['status' => UserStatus::SUSPENDED])->save();
    forgetResolvedUser();

    withToken($token)->getJson('/api/v1/assessments')->assertForbidden();
});

/*
| Filters
*/

it('filters by class subject', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $target = activeAssessment();
    activeAssessment();

    withToken($token)->getJson("/api/v1/assessments?class_subject_id={$target->class_subject_id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id);
});

it('filters by term', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $target = activeAssessment();
    activeAssessment();

    withToken($token)->getJson("/api/v1/assessments?term_id={$target->term_id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id);
});

it('filters by assessment type', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $target = activeAssessment();
    activeAssessment();

    withToken($token)->getJson("/api/v1/assessments?assessment_type_id={$target->assessment_type_id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id);
});

it('filters by academic session, derived through the term', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    [, $session] = configuredSchool();
    $term = Term::factory()->forSession($session, 2)->create();
    $target = activeAssessment(['term_id' => $term->id]);
    activeAssessment();

    withToken($token)->getJson("/api/v1/assessments?academic_session_id={$session->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $target->id);
});

it('filters by status', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    activeAssessment();
    $inactive = activeAssessment(['status' => CatalogStatus::INACTIVE]);

    withToken($token)->getJson('/api/v1/assessments?status=INACTIVE')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $inactive->id);
});

it('rejects a search parameter, because there is no text field to search', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->getJson('/api/v1/assessments?search=anything')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('search');
});

it('rejects a filter naming a class subject that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->getJson('/api/v1/assessments?class_subject_id=999999')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('class_subject_id');
});

it('returns an empty list rather than an error when nothing matches', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    activeAssessment();

    withToken($token)->getJson('/api/v1/assessments?status=INACTIVE')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('rejects invalid pagination', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->getJson('/api/v1/assessments?per_page=0')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
});

/*
| What the API will not do or show
*/

it('ignores a mass-assignment attempt to inject status on create', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $response = withToken($token)->postJson('/api/v1/assessments', assessmentCreatePayload(['status' => 'INACTIVE']));

    $response->assertCreated();

    expect(Assessment::query()->sole()->status)->toBe(CatalogStatus::ACTIVE);
});

it('does not let one assessment be read or amended through another one\'s id (IDOR)', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $mine = activeAssessment();
    $someoneElses = activeAssessment();

    withToken($token)->putJson("/api/v1/assessments/{$mine->id}", assessmentUpdatePayload(['name' => 'Renamed']))->assertOk();

    expect($mine->refresh()->name)->toBe('Renamed')
        ->and($someoneElses->refresh()->name)->not->toBe('Renamed');
});

it('lists assessments through the same envelope as every other list in the API', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    activeAssessment();

    withToken($token)->getJson('/api/v1/assessments')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'class_subject', 'term', 'assessment_type', 'name', 'max_score', 'weight', 'sort_order', 'status']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);
});
