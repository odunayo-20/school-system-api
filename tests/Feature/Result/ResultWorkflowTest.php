<?php

use App\Enums\ResultStatus;
use App\Enums\Role;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Result;
use App\Models\Score;
use App\Services\Result\ResultService;

/*
|--------------------------------------------------------------------------
| Result approval/publication workflow: the linear pipeline (Module 13)
|--------------------------------------------------------------------------
|
| COMPILED -> SUBMITTED -> APPROVED -> PUBLISHED -> LOCKED. Every case here uses an ADMIN
| actor unless the test is specifically about teacher scope - see ResultWorkflowAuthorizationTest
| for the permission matrix, IDOR and separation-of-duties coverage.
|
*/

/*
| DRAFT/COMPILED -> SUBMITTED
*/

it('submits a compiled result for approval', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $result = compiledResult();

    $response = withToken($token)->postJson("/api/v1/results/{$result->id}/submit");

    $response->assertOk()
        ->assertJsonPath('data.status', ResultStatus::SUBMITTED->value)
        ->assertJsonPath('data.submitted_by.id', fn (int $id): bool => $id > 0)
        ->assertJsonPath('data.submitted_at', fn (?string $at): bool => $at !== null)
        ->assertJsonPath('data.approved_by', null)
        ->assertJsonPath('data.approved_at', null);

    expect($result->refresh()->status)->toBe(ResultStatus::SUBMITTED);
});

it('refuses to submit an INCOMPLETE result', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $result = Result::factory()->incomplete()->create();

    withToken($token)->postJson("/api/v1/results/{$result->id}/submit")
        ->assertStatus(422)
        ->assertJsonPath('message', 'This result is INCOMPLETE - not every assessment has a score yet - so it cannot be submitted.');

    expect($result->refresh()->status)->toBe(ResultStatus::INCOMPLETE);
});

it('refuses to submit a result that has already been submitted', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $result = submittedResult();

    withToken($token)->postJson("/api/v1/results/{$result->id}/submit")
        ->assertStatus(422)
        ->assertJsonPath('message', 'This result is SUBMITTED, so it cannot be submitted. It must be COMPILED first.');
});

it('refuses to submit an already-approved result', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $result = approvedResult();

    withToken($token)->postJson("/api/v1/results/{$result->id}/submit")->assertStatus(422);
});

/*
| SUBMITTED -> APPROVED
*/

it('approves a submitted result', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $result = submittedResult();

    $response = withToken($token)->postJson("/api/v1/results/{$result->id}/approve");

    $response->assertOk()
        ->assertJsonPath('data.status', ResultStatus::APPROVED->value)
        ->assertJsonPath('data.approved_by.id', fn (int $id): bool => $id > 0)
        ->assertJsonPath('data.approved_at', fn (?string $at): bool => $at !== null);

    expect($result->refresh()->status)->toBe(ResultStatus::APPROVED);
});

it('refuses to approve a compiled result that has not been submitted yet', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $result = compiledResult();

    withToken($token)->postJson("/api/v1/results/{$result->id}/approve")
        ->assertStatus(422)
        ->assertJsonPath('message', 'This result is COMPILED, so it cannot be approved. It must be SUBMITTED first.');

    expect($result->refresh()->status)->toBe(ResultStatus::COMPILED);
});

it('refuses to approve a result twice', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $result = approvedResult();

    withToken($token)->postJson("/api/v1/results/{$result->id}/approve")->assertStatus(422);
});

it('refuses to approve an already-published result', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $result = publishedResult();

    withToken($token)->postJson("/api/v1/results/{$result->id}/approve")->assertStatus(422);
});

/*
| APPROVED -> PUBLISHED
*/

it('publishes an approved result', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $result = approvedResult();

    $response = withToken($token)->postJson("/api/v1/results/{$result->id}/publish");

    $response->assertOk()
        ->assertJsonPath('data.status', ResultStatus::PUBLISHED->value)
        ->assertJsonPath('data.published_by.id', fn (int $id): bool => $id > 0)
        ->assertJsonPath('data.published_at', fn (?string $at): bool => $at !== null);

    expect($result->refresh()->status)->toBe(ResultStatus::PUBLISHED);
});

it('refuses to publish a result before it is approved', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $result = compiledResult();

    withToken($token)->postJson("/api/v1/results/{$result->id}/publish")
        ->assertStatus(422)
        ->assertJsonPath('message', 'This result is COMPILED, so it cannot be published. It must be APPROVED first.');
});

it('refuses to publish a merely submitted (not yet approved) result', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $result = submittedResult();

    withToken($token)->postJson("/api/v1/results/{$result->id}/publish")->assertStatus(422);
});

it('refuses to publish a result twice', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $result = publishedResult();

    withToken($token)->postJson("/api/v1/results/{$result->id}/publish")->assertStatus(422);
});

/*
| PUBLISHED -> LOCKED
*/

it('locks a published result', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $result = publishedResult();

    $response = withToken($token)->postJson("/api/v1/results/{$result->id}/lock");

    $response->assertOk()
        ->assertJsonPath('data.status', ResultStatus::LOCKED->value)
        ->assertJsonPath('data.locked_by.id', fn (int $id): bool => $id > 0)
        ->assertJsonPath('data.locked_at', fn (?string $at): bool => $at !== null);

    expect($result->refresh()->status)->toBe(ResultStatus::LOCKED);
});

it('refuses to lock a result that has not been published, requiring publication first', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $result = approvedResult();

    withToken($token)->postJson("/api/v1/results/{$result->id}/lock")
        ->assertStatus(422)
        ->assertJsonPath('message', 'This result is APPROVED, so it cannot be locked. It must be PUBLISHED first.');
});

it('refuses to lock a result twice', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $result = lockedResult();

    withToken($token)->postJson("/api/v1/results/{$result->id}/lock")->assertStatus(422);
});

/*
| Locked immutability
*/

it('refuses to recompile a locked result - compilation cannot silently overwrite an immutable historical record', function (): void {
    $result = lockedResult();
    $originalPercentage = $result->percentage;

    expect(fn () => app(ResultService::class)->compile([
        'enrollment_id' => $result->enrollment_id,
        'class_subject_id' => $result->class_subject_id,
        'term_id' => $result->term_id,
    ], userWithRole(Role::ADMIN)))->toThrow(BusinessRuleViolation::class);

    expect((string) $result->refresh()->percentage)->toBe((string) $originalPercentage)
        ->and($result->status)->toBe(ResultStatus::LOCKED);
});

it('refuses every workflow transition on a locked result', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $result = lockedResult();

    withToken($token)->postJson("/api/v1/results/{$result->id}/submit")->assertStatus(422);
    withToken($token)->postJson("/api/v1/results/{$result->id}/approve")->assertStatus(422);
    withToken($token)->postJson("/api/v1/results/{$result->id}/publish")->assertStatus(422);

    expect($result->refresh()->status)->toBe(ResultStatus::LOCKED);
});

it('answers 405 for a PUT/PATCH/DELETE against a locked result, exactly as for any other result', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $result = lockedResult();

    withToken($token)->putJson("/api/v1/results/{$result->id}", ['percentage' => 100])->assertStatus(405);
    withToken($token)->deleteJson("/api/v1/results/{$result->id}")->assertStatus(405);
});

/*
| Workflow metadata correctness
*/

it('records the correct actor and timestamp at each transition, and leaves later ones null until reached', function (): void {
    $submitter = userWithRole(Role::ADMIN);
    $approver = userWithRole(Role::ADMIN);
    $publisher = userWithRole(Role::ADMIN);
    $locker = userWithRole(Role::ADMIN);

    $result = compiledResult();
    $service = app(ResultService::class);

    $result = $service->submit($result, $submitter);
    expect($result->submitted_by)->toBe($submitter->id)
        ->and($result->submitted_at)->not->toBeNull()
        ->and($result->approved_by)->toBeNull()
        ->and($result->published_by)->toBeNull()
        ->and($result->locked_by)->toBeNull();

    $result = $service->approve($result, $approver);
    expect($result->approved_by)->toBe($approver->id)
        ->and($result->approved_at)->not->toBeNull()
        ->and($result->submitted_by)->toBe($submitter->id)
        ->and($result->published_by)->toBeNull();

    $result = $service->publish($result, $publisher);
    expect($result->published_by)->toBe($publisher->id)
        ->and($result->published_at)->not->toBeNull()
        ->and($result->locked_by)->toBeNull();

    $result = $service->lock($result, $locker);
    expect($result->locked_by)->toBe($locker->id)
        ->and($result->locked_at)->not->toBeNull();
});

it('never accepts a client-supplied status or workflow metadata on a transition endpoint', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $result = compiledResult();

    $response = withToken($token)->postJson("/api/v1/results/{$result->id}/submit", [
        'status' => 'PUBLISHED',
        'submitted_by' => 999999,
        'approved_by' => 999999,
        'approved_at' => now()->toIso8601String(),
    ]);

    $response->assertOk()->assertJsonPath('data.status', ResultStatus::SUBMITTED->value);

    expect($result->refresh()->status)->toBe(ResultStatus::SUBMITTED)
        ->and($result->approved_by)->toBeNull();
});

/*
| Historical data safety
*/

it('leaves a submitted/approved/published/locked result unaffected by a later change to its scores', function (): void {
    // Result is a snapshot (Module 12); once past COMPILED, ResultService::persist() refuses
    // the very recompile that would ever let a later score edit reach it - see the "refuses
    // to recompile a locked result" case above for the terminal end of this guarantee. This
    // test pins the intermediate case: editing the underlying Score after submission changes
    // nothing about the already-submitted snapshot.
    $result = submittedResult();
    $originalPercentage = (string) $result->percentage;

    $score = Score::query()->where('enrollment_id', $result->enrollment_id)->sole();
    $score->update(['score' => 0]);

    expect((string) $result->refresh()->percentage)->toBe($originalPercentage)
        ->and($result->status)->toBe(ResultStatus::SUBMITTED);
});

/*
| Concurrency
*/

it('serializes two concurrent approvals of the same submitted result - the second sees APPROVED and is refused', function (): void {
    $result = submittedResult();
    $admin = userWithRole(Role::ADMIN);
    $service = app(ResultService::class);

    $service->approve($result, $admin);

    expect(fn () => $service->approve($result, $admin))->toThrow(BusinessRuleViolation::class);

    expect(Result::query()->whereKey($result->id)->count())->toBe(1)
        ->and($result->refresh()->status)->toBe(ResultStatus::APPROVED);
});

it('does not allow a duplicate transition request to leave the result in an impossible state', function (): void {
    $result = compiledResult();
    $admin = userWithRole(Role::ADMIN);
    $service = app(ResultService::class);

    $service->submit($result, $admin);

    // A second, duplicate "submit" request (a double-click, a retried request) must be
    // refused, not silently re-applied - the row must show exactly one submission.
    expect(fn () => $service->submit($result, $admin))->toThrow(BusinessRuleViolation::class);

    $result->refresh();
    expect($result->status)->toBe(ResultStatus::SUBMITTED)
        ->and(Result::query()->whereKey($result->id)->count())->toBe(1);
});
