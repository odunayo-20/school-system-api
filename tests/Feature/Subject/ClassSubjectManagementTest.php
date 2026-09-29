<?php

use App\Enums\CatalogStatus;
use App\Enums\Role;
use App\Models\ClassLevel;
use App\Models\ClassSubject;
use App\Models\SchoolClass;
use App\Models\Subject;
use Database\Seeders\SubjectPermissionSeeder;

/*
|--------------------------------------------------------------------------
| Class subjects: offering a subject to a class (Module 07)
|--------------------------------------------------------------------------
*/

it('offers a subject to a class', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $class = selectableSchoolClass();
    $subject = catalogSubject();

    $response = withToken($token)->postJson('/api/v1/class-subjects', classSubjectCreatePayload([
        'school_class_id' => $class->id,
        'subject_id' => $subject->id,
    ]));

    $response->assertCreated()
        ->assertJsonPath('data.status', CatalogStatus::ACTIVE->value)
        ->assertJsonPath('data.school_class.id', $class->id)
        ->assertJsonPath('data.subject.id', $subject->id);

    expect(ClassSubject::query()->count())->toBe(1);
});

it('requires a class', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $payload = classSubjectCreatePayload();
    unset($payload['school_class_id']);

    withToken($token)->postJson('/api/v1/class-subjects', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('school_class_id');
});

it('requires a subject', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $payload = classSubjectCreatePayload();
    unset($payload['subject_id']);

    withToken($token)->postJson('/api/v1/class-subjects', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('subject_id');
});

it('refuses a class that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->postJson('/api/v1/class-subjects', classSubjectCreatePayload(['school_class_id' => 999999]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('school_class_id');
});

it('refuses a subject that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->postJson('/api/v1/class-subjects', classSubjectCreatePayload(['subject_id' => 999999]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('subject_id');
});

it('refuses a class that has been retired', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $retiredClass = SchoolClass::factory()->status(CatalogStatus::ARCHIVED)->create();

    withToken($token)->postJson('/api/v1/class-subjects', classSubjectCreatePayload([
        'school_class_id' => $retiredClass->id,
    ]))->assertUnprocessable()->assertJsonValidationErrors('school_class_id');
});

it('refuses a class whose class level has been retired', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $retiredLevel = ClassLevel::factory()->status(CatalogStatus::ARCHIVED)->create();
    $class = SchoolClass::factory()->within($retiredLevel, 'JSS 1', 'JSS1')->create();

    // The class itself is ACTIVE, so this passes the form request's own check, and is
    // refused by SubjectService::assertClassSelectable() instead - the check that needs the
    // loaded class_level relation, the identical two-level guard Module 06 uses for
    // enrollments.
    withToken($token)->postJson('/api/v1/class-subjects', classSubjectCreatePayload([
        'school_class_id' => $class->id,
    ]))->assertStatus(422);

    expect(ClassSubject::query()->count())->toBe(0);
});

it('refuses a subject that has been retired', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $retiredSubject = catalogSubject(['status' => CatalogStatus::ARCHIVED]);

    withToken($token)->postJson('/api/v1/class-subjects', classSubjectCreatePayload([
        'subject_id' => $retiredSubject->id,
    ]))->assertUnprocessable()->assertJsonValidationErrors('subject_id');
});

it('refuses attaching the same subject to a class twice', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $class = selectableSchoolClass();
    $subject = catalogSubject();

    withToken($token)->postJson('/api/v1/class-subjects', classSubjectCreatePayload([
        'school_class_id' => $class->id,
        'subject_id' => $subject->id,
    ]))->assertCreated();

    withToken($token)->postJson('/api/v1/class-subjects', classSubjectCreatePayload([
        'school_class_id' => $class->id,
        'subject_id' => $subject->id,
    ]))->assertUnprocessable()->assertJsonValidationErrors('subject_id');

    expect(ClassSubject::query()->count())->toBe(1);
});

it('allows the same subject to be attached to multiple different classes', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $subject = catalogSubject();
    $classA = selectableSchoolClass();
    $classB = selectableSchoolClass();

    withToken($token)->postJson('/api/v1/class-subjects', classSubjectCreatePayload([
        'school_class_id' => $classA->id,
        'subject_id' => $subject->id,
    ]))->assertCreated();

    withToken($token)->postJson('/api/v1/class-subjects', classSubjectCreatePayload([
        'school_class_id' => $classB->id,
        'subject_id' => $subject->id,
    ]))->assertCreated();

    expect(ClassSubject::query()->where('subject_id', $subject->id)->count())->toBe(2);
});

it('allows a class to offer multiple different subjects', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $class = selectableSchoolClass();
    $math = catalogSubject();
    $english = catalogSubject();

    withToken($token)->postJson('/api/v1/class-subjects', classSubjectCreatePayload([
        'school_class_id' => $class->id,
        'subject_id' => $math->id,
    ]))->assertCreated();

    withToken($token)->postJson('/api/v1/class-subjects', classSubjectCreatePayload([
        'school_class_id' => $class->id,
        'subject_id' => $english->id,
    ]))->assertCreated();

    expect(ClassSubject::query()->where('school_class_id', $class->id)->count())->toBe(2);
});

it('has no field through which a create request can set the status', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $response = withToken($token)->postJson('/api/v1/class-subjects', classSubjectCreatePayload(['status' => 'INACTIVE']));

    $response->assertCreated()->assertJsonPath('data.status', CatalogStatus::ACTIVE->value);
});

it('shows a single class subject', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $classSubject = activeClassSubject();

    withToken($token)->getJson("/api/v1/class-subjects/{$classSubject->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $classSubject->id);
});

it('answers 404 for a class subject that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->getJson('/api/v1/class-subjects/999999')->assertNotFound();
});

it('deactivates a class subject through the ordinary amend', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $classSubject = activeClassSubject();

    withToken($token)->putJson("/api/v1/class-subjects/{$classSubject->id}", ['status' => 'INACTIVE'])
        ->assertOk()
        ->assertJsonPath('data.status', 'INACTIVE');

    expect($classSubject->refresh()->status)->toBe(CatalogStatus::INACTIVE);
});

it('reactivates a deactivated class subject', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $classSubject = activeClassSubject(['status' => CatalogStatus::INACTIVE]);

    withToken($token)->putJson("/api/v1/class-subjects/{$classSubject->id}", ['status' => 'ACTIVE'])
        ->assertOk()
        ->assertJsonPath('data.status', 'ACTIVE');
});

it('cannot reach school_class_id or subject_id through the amend endpoint', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $classSubject = activeClassSubject();
    $otherClass = selectableSchoolClass();
    $otherSubject = catalogSubject();

    withToken($token)->putJson("/api/v1/class-subjects/{$classSubject->id}", [
        'status' => 'INACTIVE',
        'school_class_id' => $otherClass->id,
        'subject_id' => $otherSubject->id,
    ])->assertOk();

    $classSubject->refresh();

    expect($classSubject->school_class_id)->not->toBe($otherClass->id)
        ->and($classSubject->subject_id)->not->toBe($otherSubject->id);
});

it('answers 405 for a PATCH', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $classSubject = activeClassSubject();

    withToken($token)->patchJson("/api/v1/class-subjects/{$classSubject->id}", ['status' => 'INACTIVE'])
        ->assertStatus(405)
        ->assertHeader('Allow');
});

it('refuses a delete outright, because a class subject is the anchor for future academic records', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $classSubject = activeClassSubject();

    withToken($token)->deleteJson("/api/v1/class-subjects/{$classSubject->id}")->assertStatus(405);

    expect(ClassSubject::query()->whereKey($classSubject->id)->exists())->toBeTrue();
});

it('has no delete permission to grant in the first place', function (): void {
    expect(SubjectPermissionSeeder::names())
        ->toContain('class_subjects.view', 'class_subjects.create', 'class_subjects.update')
        ->not->toContain('class_subjects.delete');
});
