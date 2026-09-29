<?php

use App\Enums\Role;

/*
|--------------------------------------------------------------------------
| Grading calculation: percentage in, grade information out (Module 11)
|--------------------------------------------------------------------------
|
| Every case here uses the same standard five-band scale (standardGradingBands()):
|   A: 70-100   B: 60-69.99   C: 50-59.99   D: 40-49.99   F: 0-39.99
|
*/

it('calculates the exact lower boundary of a band', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $scale = configuredGradingScale();

    withToken($token)->postJson("/api/v1/grading-scales/{$scale->id}/calculate", ['percentage' => 70])
        ->assertOk()
        ->assertJsonPath('data.grade', 'A')
        ->assertJsonPath('data.grade_point', '5.00')
        ->assertJsonPath('data.remark', 'Excellent');
});

it('calculates the exact upper boundary of a band', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $scale = configuredGradingScale();

    withToken($token)->postJson("/api/v1/grading-scales/{$scale->id}/calculate", ['percentage' => 69.99])
        ->assertOk()
        ->assertJsonPath('data.grade', 'B');
});

it('distinguishes 69.99 from 70.00 across the same boundary', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $scale = configuredGradingScale();

    withToken($token)->postJson("/api/v1/grading-scales/{$scale->id}/calculate", ['percentage' => 69.99])
        ->assertOk()->assertJsonPath('data.grade', 'B');

    withToken($token)->postJson("/api/v1/grading-scales/{$scale->id}/calculate", ['percentage' => 70.00])
        ->assertOk()->assertJsonPath('data.grade', 'A');

    withToken($token)->postJson("/api/v1/grading-scales/{$scale->id}/calculate", ['percentage' => 70.01])
        ->assertOk()->assertJsonPath('data.grade', 'A');
});

it('calculates a decimal percentage between boundaries', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $scale = configuredGradingScale();

    // 17.5 / 20 = 87.5 - the brief's own worked decimal example.
    withToken($token)->postJson("/api/v1/grading-scales/{$scale->id}/calculate", ['percentage' => 87.5])
        ->assertOk()
        ->assertJsonPath('data.percentage', 87.5)
        ->assertJsonPath('data.grade', 'A');
});

it('calculates zero', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $scale = configuredGradingScale();

    withToken($token)->postJson("/api/v1/grading-scales/{$scale->id}/calculate", ['percentage' => 0])
        ->assertOk()
        ->assertJsonPath('data.grade', 'F');
});

it('calculates 100', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $scale = configuredGradingScale();

    withToken($token)->postJson("/api/v1/grading-scales/{$scale->id}/calculate", ['percentage' => 100])
        ->assertOk()
        ->assertJsonPath('data.grade', 'A');
});

it('reports an uncovered percentage explicitly, never silently guessing a grade', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $scale = configuredGradingScale();

    // Delete the F band, deliberately opening a gap at the bottom of the scale.
    $scale->items()->where('grade', 'F')->delete();

    $response = withToken($token)->postJson("/api/v1/grading-scales/{$scale->id}/calculate", ['percentage' => 10]);

    $response->assertOk()
        ->assertJsonPath('data.grade', null)
        ->assertJsonPath('data.grade_point', null)
        ->assertJsonPath('data.remark', null)
        ->assertJsonPath('data.matched_band', null);
});

it('refuses a negative percentage', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $scale = configuredGradingScale();

    withToken($token)->postJson("/api/v1/grading-scales/{$scale->id}/calculate", ['percentage' => -1])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('percentage');
});

it('refuses a percentage above 100', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $scale = configuredGradingScale();

    withToken($token)->postJson("/api/v1/grading-scales/{$scale->id}/calculate", ['percentage' => 100.01])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('percentage');
});

it('requires a percentage', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $scale = configuredGradingScale();

    withToken($token)->postJson("/api/v1/grading-scales/{$scale->id}/calculate", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('percentage');
});

it('cannot be bypassed with a client-supplied grade or grade point', function (): void {
    // The server calculates every derived value; nothing about the request body can steer
    // the answer beyond naming the percentage.
    $token = loginAs(userWithRole(Role::ADMIN));
    $scale = configuredGradingScale();

    withToken($token)->postJson("/api/v1/grading-scales/{$scale->id}/calculate", [
        'percentage' => 10,
        'grade' => 'A',
        'grade_point' => 5,
    ])->assertOk()->assertJsonPath('data.grade', 'F');
});

it('answers 404 for a grading scale that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->postJson('/api/v1/grading-scales/999999/calculate', ['percentage' => 85])
        ->assertNotFound();
});

it('refuses calculation to an unauthenticated caller', function (): void {
    $scale = configuredGradingScale();

    test()->postJson("/api/v1/grading-scales/{$scale->id}/calculate", ['percentage' => 85])
        ->assertUnauthorized();
});

it('refuses calculation to a user with no grading_scales permission', function (): void {
    $teacher = userWithRole(Role::STAFF);
    $scale = configuredGradingScale();

    withToken(loginAs($teacher))->postJson("/api/v1/grading-scales/{$scale->id}/calculate", ['percentage' => 85])
        ->assertForbidden();
});
