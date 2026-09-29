<?php

use App\Enums\Role;
use App\Models\Score;

/*
|--------------------------------------------------------------------------
| Bulk score entry: one assessment, many students (Module 10)
|--------------------------------------------------------------------------
*/

it('records a valid batch of scores in one call', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment] = matchedScoreContext(['max_score' => 20]);
    $enrollmentIds = rosterEnrollments($assessment, 3);

    $response = withToken($token)->postJson('/api/v1/scores/bulk', [
        'assessment_id' => $assessment->id,
        'scores' => [
            ['enrollment_id' => $enrollmentIds[0], 'score' => 17],
            ['enrollment_id' => $enrollmentIds[1], 'score' => 14],
            ['enrollment_id' => $enrollmentIds[2], 'score' => 19.5],
        ],
    ]);

    $response->assertCreated()->assertJsonCount(3, 'data');

    expect(Score::query()->where('assessment_id', $assessment->id)->count())->toBe(3);
});

it('requires an assessment', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->postJson('/api/v1/scores/bulk', [
        'scores' => [['enrollment_id' => 1, 'score' => 10]],
    ])->assertUnprocessable()->assertJsonValidationErrors('assessment_id');
});

it('requires at least one row', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment] = matchedScoreContext();

    withToken($token)->postJson('/api/v1/scores/bulk', [
        'assessment_id' => $assessment->id,
        'scores' => [],
    ])->assertUnprocessable()->assertJsonValidationErrors('scores');
});

it('refuses a batch larger than the row ceiling', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment] = matchedScoreContext();
    $enrollmentIds = rosterEnrollments($assessment, 2);

    $rows = array_fill(0, 101, ['enrollment_id' => $enrollmentIds[0], 'score' => 10]);

    withToken($token)->postJson('/api/v1/scores/bulk', [
        'assessment_id' => $assessment->id,
        'scores' => $rows,
    ])->assertUnprocessable()->assertJsonValidationErrors('scores');
});

it('accepts a batch at exactly the row ceiling', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment] = matchedScoreContext();
    $enrollmentIds = rosterEnrollments($assessment, 100);

    $rows = collect($enrollmentIds)->map(fn (int $id): array => ['enrollment_id' => $id, 'score' => 10])->all();

    withToken($token)->postJson('/api/v1/scores/bulk', [
        'assessment_id' => $assessment->id,
        'scores' => $rows,
    ])->assertCreated()->assertJsonCount(100, 'data');
});

it('refuses the same enrollment appearing twice in one batch', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment] = matchedScoreContext();
    $enrollmentIds = rosterEnrollments($assessment, 1);

    withToken($token)->postJson('/api/v1/scores/bulk', [
        'assessment_id' => $assessment->id,
        'scores' => [
            ['enrollment_id' => $enrollmentIds[0], 'score' => 10],
            ['enrollment_id' => $enrollmentIds[0], 'score' => 15],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors(['scores.0.enrollment_id', 'scores.1.enrollment_id']);

    expect(Score::query()->count())->toBe(0);
});

it('refuses a row whose score exceeds the assessment maximum, and reports which row', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment] = matchedScoreContext(['max_score' => 20]);
    $enrollmentIds = rosterEnrollments($assessment, 2);

    $response = withToken($token)->postJson('/api/v1/scores/bulk', [
        'assessment_id' => $assessment->id,
        'scores' => [
            ['enrollment_id' => $enrollmentIds[0], 'score' => 15],
            ['enrollment_id' => $enrollmentIds[1], 'score' => 25],
        ],
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('scores.1.score');

    expect(Score::query()->count())->toBe(0);
});

it('refuses a row naming an enrollment that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment] = matchedScoreContext();
    $enrollmentIds = rosterEnrollments($assessment, 1);

    withToken($token)->postJson('/api/v1/scores/bulk', [
        'assessment_id' => $assessment->id,
        'scores' => [
            ['enrollment_id' => $enrollmentIds[0], 'score' => 10],
            ['enrollment_id' => 999999, 'score' => 12],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors('scores.1.enrollment_id');

    expect(Score::query()->count())->toBe(0);
});

it('refuses a row whose enrollment belongs to an unrelated class, without saving any row - all or nothing', function (): void {
    // The row-level guarantee the brief asks for by name: an unrelated enrollment injected
    // into an otherwise-valid batch must not corrupt the dataset by letting the OTHER, valid
    // rows through.
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment] = matchedScoreContext();
    $enrollmentIds = rosterEnrollments($assessment, 2);
    [, $unrelatedEnrollment] = matchedScoreContext();

    $response = withToken($token)->postJson('/api/v1/scores/bulk', [
        'assessment_id' => $assessment->id,
        'scores' => [
            ['enrollment_id' => $enrollmentIds[0], 'score' => 15],
            ['enrollment_id' => $enrollmentIds[1], 'score' => 12],
            ['enrollment_id' => $unrelatedEnrollment->id, 'score' => 8],
        ],
    ]);

    $response->assertStatus(422);
    expect($response->json('errors'))->toHaveKey('scores.2.enrollment_id');

    // Nothing was saved - not even the two rows that were individually valid.
    expect(Score::query()->count())->toBe(0);
});

it('refuses a batch when one row already has a score for this assessment, saving nothing', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment] = matchedScoreContext();
    $enrollmentIds = rosterEnrollments($assessment, 2);

    recordedScore(['assessment_id' => $assessment->id, 'enrollment_id' => $enrollmentIds[0]]);

    $response = withToken($token)->postJson('/api/v1/scores/bulk', [
        'assessment_id' => $assessment->id,
        'scores' => [
            ['enrollment_id' => $enrollmentIds[0], 'score' => 15],
            ['enrollment_id' => $enrollmentIds[1], 'score' => 12],
        ],
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('scores.0.enrollment_id');

    // Only the pre-existing score exists; the second (otherwise valid) row was never saved.
    expect(Score::query()->where('assessment_id', $assessment->id)->count())->toBe(1);
});

it('refuses an assessment that does not exist or is not active', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->postJson('/api/v1/scores/bulk', [
        'assessment_id' => 999999,
        'scores' => [['enrollment_id' => 1, 'score' => 10]],
    ])->assertUnprocessable()->assertJsonValidationErrors('assessment_id');
});

/*
| Authorization
*/

it('refuses bulk entry to an unauthenticated caller', function (): void {
    test()->postJson('/api/v1/scores/bulk', ['assessment_id' => 1, 'scores' => []])->assertUnauthorized();
});

it('refuses bulk entry to a user with no scores.create permission', function (): void {
    $token = loginAs(userWithRole(Role::REGISTRAR));
    [$assessment] = matchedScoreContext();

    withToken($token)->postJson('/api/v1/scores/bulk', [
        'assessment_id' => $assessment->id,
        'scores' => [['enrollment_id' => 1, 'score' => 10]],
    ])->assertForbidden();
});

it('lets an assigned teacher submit a batch for their own class subject', function (): void {
    [$assessment] = matchedScoreContext(['max_score' => 20]);
    $teacher = teacherAssignedTo($assessment->classSubject, $assessment->term->academicSession);
    $enrollmentIds = rosterEnrollments($assessment, 2);

    $token = loginAs($teacher->user);

    withToken($token)->postJson('/api/v1/scores/bulk', [
        'assessment_id' => $assessment->id,
        'scores' => [
            ['enrollment_id' => $enrollmentIds[0], 'score' => 15],
            ['enrollment_id' => $enrollmentIds[1], 'score' => 18],
        ],
    ])->assertCreated();
});

it('refuses an unassigned teacher submitting a batch for a class subject that is not theirs', function (): void {
    [$assessment] = matchedScoreContext();
    $unrelatedTeacher = eligibleTeacher();
    $enrollmentIds = rosterEnrollments($assessment, 2);

    $token = loginAs($unrelatedTeacher->user);

    $response = withToken($token)->postJson('/api/v1/scores/bulk', [
        'assessment_id' => $assessment->id,
        'scores' => [
            ['enrollment_id' => $enrollmentIds[0], 'score' => 15],
            ['enrollment_id' => $enrollmentIds[1], 'score' => 18],
        ],
    ]);

    $response->assertForbidden();

    expect(Score::query()->count())->toBe(0);
});
