<?php

use App\Enums\Role;
use App\Enums\StudentStatus;
use App\Enums\UserStatus;
use App\Models\Student;

/*
|--------------------------------------------------------------------------
| The pupil lifecycle (Module 04)
|--------------------------------------------------------------------------
|
| A pupil's roll status, and the one rule this module treats as a matter of fact rather
| than a preference: a child who has left the school for good does not come back.
|
| A pupil's lifecycle is deliberately NOT shaped like staff employment. Staff needed
| ACTIVE, INACTIVE, ON_HOLIDAY, TERMINATED, and two dedicated POST endpoints to move between
| them, because ending somebody's employment is an action somebody has to be authorised to
| take. A pupil needs four values and none of them is somebody's decision to defend:
|
|     ACTIVE      - on the roll, being taught
|     INACTIVE    - on the roll, not being taught right now; a transfer out of area, a long
|                    absence, a term out. Reversible, and the common case.
|     GRADUATED   - finished. The good terminal state.
|     WITHDRAWN   - left before finishing. The other terminal state.
|
| Two terminal states rather than one because "this child left the school" and "this child
| completed the school" are different facts, and collapsing them into a single LEFT would
| lose the distinction the school most wants to keep.
|
*/

it('starts every new pupil as active', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    withToken($token)->postJson('/api/v1/students', studentCreatePayload())
        ->assertCreated()
        ->assertJsonPath('data.status', StudentStatus::ACTIVE->value);
});

it('marks a pupil inactive and back again', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);
    $student = pupil();

    withToken($token)->putJson("/api/v1/students/{$student->id}", studentUpdatePayload([
        'status' => StudentStatus::INACTIVE->value,
    ]))
        ->assertOk()
        ->assertJsonPath('data.status', StudentStatus::INACTIVE->value);

    expect($student->refresh()->isActive())->toBeFalse();

    // Inactive is the reversible state, and coming back is the whole point of it.
    withToken($token)->putJson("/api/v1/students/{$student->id}", studentUpdatePayload([
        'status' => StudentStatus::ACTIVE->value,
    ]))
        ->assertOk()
        ->assertJsonPath('data.status', StudentStatus::ACTIVE->value);

    expect($student->refresh()->isActive())->toBeTrue();
});

it('marks a pupil graduated', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);
    $student = pupil();

    withToken($token)->putJson("/api/v1/students/{$student->id}", studentUpdatePayload([
        'status' => StudentStatus::GRADUATED->value,
    ]))
        ->assertOk()
        ->assertJsonPath('data.status', StudentStatus::GRADUATED->value);

    expect($student->refresh()->isTerminal())->toBeTrue();
});

it('marks a pupil withdrawn, which is a different fact from graduating', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);
    $student = pupil();

    withToken($token)->putJson("/api/v1/students/{$student->id}", studentUpdatePayload([
        'status' => StudentStatus::WITHDRAWN->value,
    ]))
        ->assertOk()
        ->assertJsonPath('data.status', StudentStatus::WITHDRAWN->value);

    expect($student->refresh()->isTerminal())->toBeTrue();
});

it('refuses to amend a pupil who has left, whether they graduated or withdrew', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    foreach ([StudentStatus::GRADUATED, StudentStatus::WITHDRAWN] as $terminal) {
        $student = pupil(['status' => $terminal]);

        $response = withToken($token)->putJson("/api/v1/students/{$student->id}", studentUpdatePayload([
            'last_name' => 'Changed',
        ]));

        // 422 with a message that names the obstacle, exactly as Module 03 reports a
        // terminated employment. This is a BusinessRuleViolation rather than a validation
        // failure: the payload is well formed and permitted, and it is the pupil's current
        // state that makes it impossible. Attaching a field key would imply the client
        // should change a value in the request, which would be wrong advice.
        $response->assertUnprocessable()
            ->assertJsonPath('message', 'This pupil has already left the school, so the record can no longer be amended.');

        // The record survives untouched. The history of a child is not erasable because
        // somebody corrected a misspelling after they left.
        expect($student->refresh()->last_name)->not->toBe('Changed')
            ->and(Student::query()->whereKey($student->id)->exists())->toBeTrue();
    }
});

it('refuses to put a pupil who has left back on the roll', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);
    $student = pupil(['status' => StudentStatus::WITHDRAWN]);

    // The rule that matters most. A withdrawal is not a long absence, and a later amend must
    // not be able to quietly reinstate a child and have them reappear on a current roll.
    withToken($token)->putJson("/api/v1/students/{$student->id}", studentUpdatePayload([
        'status' => StudentStatus::ACTIVE->value,
    ]))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This pupil has already left the school, so the record can no longer be amended.');

    expect($student->refresh()->status)->toBe(StudentStatus::WITHDRAWN);
});

it('refuses to swap one terminal status for the other', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);
    $student = pupil(['status' => StudentStatus::WITHDRAWN]);

    // Somebody who withdrew did not in fact graduate, and somebody who graduated did not
    // withdraw. Correcting a genuine mistake is a data migration with a person who can
    // authorise it, not something an ordinary amend may do.
    withToken($token)->putJson("/api/v1/students/{$student->id}", studentUpdatePayload([
        'status' => StudentStatus::GRADUATED->value,
    ]))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This pupil has already left the school, so the record can no longer be amended.');

    expect($student->refresh()->status)->toBe(StudentStatus::WITHDRAWN);
});

it('refuses a retry of the very request that marked a pupil withdrawn', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);
    $student = pupil(['status' => StudentStatus::WITHDRAWN]);

    // A departed pupil's record is read only IN FULL, not merely protected against being
    // reinstated - the same rule Module 03 applies to a terminated employment. That means a
    // client which retries the request that recorded the withdrawal is refused too, even
    // though the retry would change nothing.
    //
    // This is a deliberate trade. An earlier draft allowed the no-op retry, on the argument
    // that retrying a PUT should always be safe; but update() refuses every amend on a
    // terminal record, so that branch was unreachable. Refusing outright is the more honest
    // answer: the record is closed, and the 422 says so in words rather than leaving the
    // client to guess which amends are still permitted.
    withToken($token)->putJson("/api/v1/students/{$student->id}", studentUpdatePayload([
        'status' => StudentStatus::WITHDRAWN->value,
    ]))
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This pupil has already left the school, so the record can no longer be amended.');

    expect($student->refresh()->status)->toBe(StudentStatus::WITHDRAWN);
});

it('is idempotent for a pupil who has not left, so a retried amend is safe', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);
    $student = pupil(['status' => StudentStatus::INACTIVE]);

    // The retry guarantee still holds where the record is open, which is where it matters.
    // A client that times out and resends an amend of a current pupil gets a success rather
    // than being told its write failed.
    withToken($token)->putJson("/api/v1/students/{$student->id}", studentUpdatePayload([
        'last_name' => 'Amended',
    ]))->assertOk();

    withToken($token)->putJson("/api/v1/students/{$student->id}", studentUpdatePayload([
        'last_name' => 'Amended',
    ]))->assertOk();

    expect($student->refresh()->last_name)->toBe('Amended');
});

it('leaves the status alone when an amend does not mention one', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);
    $student = pupil(['status' => StudentStatus::INACTIVE]);

    withToken($token)->putJson("/api/v1/students/{$student->id}", studentUpdatePayload([
        'last_name' => 'Amended',
    ]))
        ->assertOk()
        ->assertJsonPath('data.status', StudentStatus::INACTIVE->value);

    expect($student->refresh()->status)->toBe(StudentStatus::INACTIVE);
});

it('refuses a lifecycle value the enum does not define', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);
    $student = pupil();

    withToken($token)->putJson("/api/v1/students/{$student->id}", studentUpdatePayload([
        'status' => 'EXPELLED',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status')
        ->assertJsonPath('errors.status.0', 'The status must be one of: ACTIVE, INACTIVE, GRADUATED, WITHDRAWN.');

    expect($student->refresh()->status)->toBe(StudentStatus::ACTIVE);
});

it('keeps a departed pupil readable, because the school still needs to find them', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);
    $student = pupil(['status' => StudentStatus::GRADUATED]);

    // Refusing to amend a record is not the same as hiding it. A registrar looking up a
    // former pupil is the most ordinary thing in the world, and the record is exactly why
    // departure is recorded as a status rather than a deletion.
    withToken($token)->getJson("/api/v1/students/{$student->id}")
        ->assertOk()
        ->assertJsonPath('data.status', StudentStatus::GRADUATED->value);

    expect(withToken($token)->getJson('/api/v1/students?status=GRADUATED')->json('data'))
        ->toHaveCount(1);
});

it('does not touch a portal account when a pupil is marked withdrawn', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);
    $student = pupilWithAccount();

    withToken($token)->putJson("/api/v1/students/{$student->id}", studentUpdatePayload([
        'status' => StudentStatus::WITHDRAWN->value,
    ]))->assertOk();

    // The roll and the login are two different questions. A child who moved away is off the
    // roll and still has a working portal login, and deactivating that account is Module 01's
    // job behind users.* - not something a registrar's amend should reach sideways into.
    // This is the seam Module 03 had to cut, and the same one seen from the other side.
    expect($student->refresh()->user->status)->toBe(UserStatus::ACTIVE)
        ->and($student->user->status)->toBe(UserStatus::ACTIVE);
});

it('does not touch a portal account when a pupil is marked inactive', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);
    $student = pupilWithAccount();

    withToken($token)->putJson("/api/v1/students/{$student->id}", studentUpdatePayload([
        'status' => StudentStatus::INACTIVE->value,
    ]))->assertOk();

    expect($student->refresh()->user->status)->toBe(UserStatus::ACTIVE);
});

it('lets a registrar record a departure, because the roll is their job', function (): void {
    $registrar = userWithRole(Role::REGISTRAR);
    $token = loginAs($registrar);
    $student = pupil();

    // Module 03 withheld staff.deactivate from registrars, because ending somebody's
    // employment while their login is still open is a supervisory decision. None of that
    // applies to a child's roll status: recording that a child moved away is the
    // registrar's ordinary record-keeping, and the terminal rule - not a withheld
    // permission - is what stops the status being abused.
    withToken($token)->putJson("/api/v1/students/{$student->id}", studentUpdatePayload([
        'status' => StudentStatus::WITHDRAWN->value,
    ]))
        ->assertOk()
        ->assertJsonPath('data.status', StudentStatus::WITHDRAWN->value);

    expect($student->refresh()->isTerminal())->toBeTrue();
});

it('reports the terminal rule as a 422 and not a 500, so a client can act on it', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);
    $student = pupil(['status' => StudentStatus::GRADUATED]);

    $response = withToken($token)->putJson("/api/v1/students/{$student->id}", studentUpdatePayload([
        'first_name' => 'Different',
    ]));

    // A 422 with a human-readable message: the client learns that the child has left, which
    // is something it can show a registrar, rather than a 500 that tells it to retry a
    // request that will never succeed.
    $response->assertUnprocessable()
        ->assertJsonStructure(['message'])
        ->assertJsonPath('message', 'This pupil has already left the school, so the record can no longer be amended.');

    expect($response->json('message'))->toBeString();
});
