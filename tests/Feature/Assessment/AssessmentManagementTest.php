<?php

use App\Enums\CatalogStatus;
use App\Enums\Role;
use App\Enums\TermStatus;
use App\Models\Assessment;
use App\Models\ClassLevel;
use App\Models\ClassSubject;
use App\Models\SchoolClass;
use App\Models\Term;
use Database\Seeders\AssessmentPermissionSeeder;

/*
|--------------------------------------------------------------------------
| Assessments: configuring an assessment against a class subject and term (Module 09)
|--------------------------------------------------------------------------
*/

it('configures an assessment', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $classSubject = activeClassSubject();
    $term = eligibleTerm();
    $assessmentType = catalogAssessmentType();

    $response = withToken($token)->postJson('/api/v1/assessments', assessmentCreatePayload([
        'class_subject_id' => $classSubject->id,
        'term_id' => $term->id,
        'assessment_type_id' => $assessmentType->id,
        'name' => 'CA 1',
        'max_score' => 20,
        'weight' => 10,
    ]));

    $response->assertCreated()
        ->assertJsonPath('data.name', 'CA 1')
        ->assertJsonPath('data.max_score', '20.00')
        ->assertJsonPath('data.weight', '10.00')
        ->assertJsonPath('data.status', CatalogStatus::ACTIVE->value)
        ->assertJsonPath('data.class_subject.id', $classSubject->id)
        ->assertJsonPath('data.term.id', $term->id)
        ->assertJsonPath('data.assessment_type.id', $assessmentType->id);

    expect(Assessment::query()->count())->toBe(1);
});

it('requires a class subject', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $payload = assessmentCreatePayload();
    unset($payload['class_subject_id']);

    withToken($token)->postJson('/api/v1/assessments', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('class_subject_id');
});

it('requires a term', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $payload = assessmentCreatePayload();
    unset($payload['term_id']);

    withToken($token)->postJson('/api/v1/assessments', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('term_id');
});

it('requires an assessment type', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $payload = assessmentCreatePayload();
    unset($payload['assessment_type_id']);

    withToken($token)->postJson('/api/v1/assessments', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('assessment_type_id');
});

it('requires a name', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $payload = assessmentCreatePayload();
    unset($payload['name']);

    withToken($token)->postJson('/api/v1/assessments', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});

it('requires a max score', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $payload = assessmentCreatePayload();
    unset($payload['max_score']);

    withToken($token)->postJson('/api/v1/assessments', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('max_score');
});

it('refuses a zero or negative max score', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->postJson('/api/v1/assessments', assessmentCreatePayload(['max_score' => 0]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('max_score');

    withToken($token)->postJson('/api/v1/assessments', assessmentCreatePayload(['max_score' => -5]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('max_score');
});

it('accepts an assessment with no weight at all', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $response = withToken($token)->postJson('/api/v1/assessments', assessmentCreatePayload());

    $response->assertCreated()->assertJsonPath('data.weight', null);
});

it('refuses a weight greater than 100', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->postJson('/api/v1/assessments', assessmentCreatePayload(['weight' => 150]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('weight');
});

it('does not require the weights of several assessments in the same class subject and term to total 100', function (): void {
    // Explicitly NOT enforced - see the assessments migration and ValidatesAssessmentRecord for
    // why weighting policy is a future grading-scheme module's decision, not this one's.
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $classSubject = activeClassSubject();
    $term = eligibleTerm();

    withToken($token)->postJson('/api/v1/assessments', assessmentCreatePayload([
        'class_subject_id' => $classSubject->id,
        'term_id' => $term->id,
        'name' => 'CA 1',
        'weight' => 60,
    ]))->assertCreated();

    withToken($token)->postJson('/api/v1/assessments', assessmentCreatePayload([
        'class_subject_id' => $classSubject->id,
        'term_id' => $term->id,
        'name' => 'CA 2',
        'weight' => 60,
    ]))->assertCreated();

    expect(Assessment::query()->where('class_subject_id', $classSubject->id)->sum('weight'))->toEqual(120);
});

it('refuses a class subject that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->postJson('/api/v1/assessments', assessmentCreatePayload(['class_subject_id' => 999999]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('class_subject_id');
});

it('refuses a class subject that has been deactivated', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $classSubject = activeClassSubject(['status' => CatalogStatus::INACTIVE]);

    withToken($token)->postJson('/api/v1/assessments', assessmentCreatePayload(['class_subject_id' => $classSubject->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('class_subject_id');
});

it('refuses a class subject whose class has been retired', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $class = SchoolClass::factory()->status(CatalogStatus::ARCHIVED)->create();
    $classSubject = ClassSubject::factory()->forClass($class)->create();

    withToken($token)->postJson('/api/v1/assessments', assessmentCreatePayload(['class_subject_id' => $classSubject->id]))
        ->assertStatus(422);

    expect(Assessment::query()->count())->toBe(0);
});

it('refuses a class subject whose class level has been retired', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $retiredLevel = ClassLevel::factory()->status(CatalogStatus::ARCHIVED)->create();
    $class = SchoolClass::factory()->within($retiredLevel, 'JSS 1', 'JSS1')->create();
    $classSubject = ClassSubject::factory()->forClass($class)->create();

    // The class subject and its class are both ACTIVE, so this passes the form request's own
    // checks and is refused by AssessmentService::assertClassSubjectSelectable() instead - the
    // check that needs the loaded class_level relation, the identical three-level guard Module
    // 06, Module 07 and Module 08 each use for their own class-hierarchy references.
    withToken($token)->postJson('/api/v1/assessments', assessmentCreatePayload(['class_subject_id' => $classSubject->id]))
        ->assertStatus(422);

    expect(Assessment::query()->count())->toBe(0);
});

it('refuses a term that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->postJson('/api/v1/assessments', assessmentCreatePayload(['term_id' => 999999]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('term_id');
});

it('refuses a term that has already been completed', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $term = Term::factory()->completed()->create();

    withToken($token)->postJson('/api/v1/assessments', assessmentCreatePayload(['term_id' => $term->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('term_id');
});

it('accepts a term that is active or still upcoming', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    foreach ([TermStatus::UPCOMING, TermStatus::ACTIVE] as $status) {
        $term = Term::factory()->status($status)->create();

        withToken($token)->postJson('/api/v1/assessments', assessmentCreatePayload(['term_id' => $term->id]))
            ->assertCreated();
    }
});

it('refuses an assessment type that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->postJson('/api/v1/assessments', assessmentCreatePayload(['assessment_type_id' => 999999]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('assessment_type_id');
});

it('refuses an assessment type that has been retired', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $retiredType = catalogAssessmentType(['status' => CatalogStatus::ARCHIVED]);

    withToken($token)->postJson('/api/v1/assessments', assessmentCreatePayload(['assessment_type_id' => $retiredType->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('assessment_type_id');
});

it('refuses a duplicate name for the same class subject and term', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $classSubject = activeClassSubject();
    $term = eligibleTerm();

    withToken($token)->postJson('/api/v1/assessments', assessmentCreatePayload([
        'class_subject_id' => $classSubject->id,
        'term_id' => $term->id,
        'name' => 'CA 1',
    ]))->assertCreated();

    withToken($token)->postJson('/api/v1/assessments', assessmentCreatePayload([
        'class_subject_id' => $classSubject->id,
        'term_id' => $term->id,
        'name' => 'CA 1',
    ]))->assertUnprocessable()->assertJsonValidationErrors('name');

    expect(Assessment::query()->count())->toBe(1);
});

it('allows several differently named assessments of the same type for the same class subject and term', function (): void {
    // The exact scenario the brief names: CA1, CA2, CA3 all category "CA", all coexisting.
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $classSubject = activeClassSubject();
    $term = eligibleTerm();
    $ca = catalogAssessmentType(['name' => 'Continuous Assessment']);

    foreach (['CA 1', 'CA 2', 'CA 3'] as $name) {
        withToken($token)->postJson('/api/v1/assessments', assessmentCreatePayload([
            'class_subject_id' => $classSubject->id,
            'term_id' => $term->id,
            'assessment_type_id' => $ca->id,
            'name' => $name,
        ]))->assertCreated();
    }

    expect(Assessment::query()->where('class_subject_id', $classSubject->id)->count())->toBe(3);
});

it('allows the same assessment name to be reused for a different class subject or term', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $classSubject = activeClassSubject();
    $term = eligibleTerm();

    withToken($token)->postJson('/api/v1/assessments', assessmentCreatePayload([
        'class_subject_id' => $classSubject->id,
        'term_id' => $term->id,
        'name' => 'CA 1',
    ]))->assertCreated();

    // Different class subject, same term and name.
    withToken($token)->postJson('/api/v1/assessments', assessmentCreatePayload([
        'class_subject_id' => activeClassSubject()->id,
        'term_id' => $term->id,
        'name' => 'CA 1',
    ]))->assertCreated();

    // Same class subject, different term, same name.
    withToken($token)->postJson('/api/v1/assessments', assessmentCreatePayload([
        'class_subject_id' => $classSubject->id,
        'term_id' => eligibleTerm()->id,
        'name' => 'CA 1',
    ]))->assertCreated();

    expect(Assessment::query()->count())->toBe(3);
});

it('has no field through which a create request can set the status', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    $response = withToken($token)->postJson('/api/v1/assessments', assessmentCreatePayload(['status' => 'INACTIVE']));

    $response->assertCreated()->assertJsonPath('data.status', CatalogStatus::ACTIVE->value);
});

it('shows a single assessment', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $assessment = activeAssessment();

    withToken($token)->getJson("/api/v1/assessments/{$assessment->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $assessment->id);
});

it('answers 404 for an assessment that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));

    withToken($token)->getJson('/api/v1/assessments/999999')->assertNotFound();
});

it('amends an assessment\'s detail fields', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $assessment = activeAssessment(['name' => 'CA 1', 'max_score' => 20]);

    withToken($token)->putJson("/api/v1/assessments/{$assessment->id}", assessmentUpdatePayload([
        'name' => 'CA 1 (Resit)',
        'max_score' => 25,
        'weight' => 15,
        'sort_order' => 3,
    ]))->assertOk()
        ->assertJsonPath('data.name', 'CA 1 (Resit)')
        ->assertJsonPath('data.max_score', '25.00')
        ->assertJsonPath('data.weight', '15.00')
        ->assertJsonPath('data.sort_order', 3);

    expect($assessment->refresh()->name)->toBe('CA 1 (Resit)');
});

it('allows updating an assessment without changing its own unique name', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $assessment = activeAssessment(['name' => 'CA 1']);

    withToken($token)->putJson("/api/v1/assessments/{$assessment->id}", assessmentUpdatePayload([
        'name' => 'CA 1',
        'max_score' => 30,
    ]))->assertOk()->assertJsonPath('data.max_score', '30.00');
});

it('refuses to amend an assessment to a name already used by another in the same class subject and term', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $classSubject = activeClassSubject();
    $term = eligibleTerm();

    activeAssessment(['class_subject_id' => $classSubject->id, 'term_id' => $term->id, 'name' => 'CA 1']);
    $assessment = activeAssessment(['class_subject_id' => $classSubject->id, 'term_id' => $term->id, 'name' => 'CA 2']);

    withToken($token)->putJson("/api/v1/assessments/{$assessment->id}", assessmentUpdatePayload(['name' => 'CA 1']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');

    expect($assessment->refresh()->name)->toBe('CA 2');
});

it('cannot reach class_subject_id, term_id or assessment_type_id through the amend endpoint', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $assessment = activeAssessment();
    $otherClassSubject = activeClassSubject();
    $otherTerm = eligibleTerm();
    $otherType = catalogAssessmentType();

    withToken($token)->putJson("/api/v1/assessments/{$assessment->id}", assessmentUpdatePayload([
        'class_subject_id' => $otherClassSubject->id,
        'term_id' => $otherTerm->id,
        'assessment_type_id' => $otherType->id,
    ]))->assertOk();

    $assessment->refresh();

    expect($assessment->class_subject_id)->not->toBe($otherClassSubject->id)
        ->and($assessment->term_id)->not->toBe($otherTerm->id)
        ->and($assessment->assessment_type_id)->not->toBe($otherType->id);
});

it('changes an assessment status to inactive and back through the ordinary amend', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $assessment = activeAssessment();

    withToken($token)->putJson("/api/v1/assessments/{$assessment->id}", assessmentUpdatePayload([
        'name' => $assessment->name,
        'max_score' => (float) $assessment->max_score,
        'status' => 'INACTIVE',
    ]))->assertOk()->assertJsonPath('data.status', 'INACTIVE');

    withToken($token)->putJson("/api/v1/assessments/{$assessment->id}", assessmentUpdatePayload([
        'name' => $assessment->name,
        'max_score' => (float) $assessment->max_score,
        'status' => 'ACTIVE',
    ]))->assertOk()->assertJsonPath('data.status', 'ACTIVE');
});

it('answers 405 for a PATCH', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $assessment = activeAssessment();

    withToken($token)->patchJson("/api/v1/assessments/{$assessment->id}", assessmentUpdatePayload())
        ->assertStatus(405)
        ->assertHeader('Allow');
});

it('refuses a delete outright, because an assessment is the anchor for future score records', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    $assessment = activeAssessment();

    withToken($token)->deleteJson("/api/v1/assessments/{$assessment->id}")->assertStatus(405);

    expect(Assessment::query()->whereKey($assessment->id)->exists())->toBeTrue();
});

it('has no delete permission to grant in the first place', function (): void {
    expect(AssessmentPermissionSeeder::names())
        ->toContain('assessments.view', 'assessments.create', 'assessments.update')
        ->not->toContain('assessments.delete');
});
