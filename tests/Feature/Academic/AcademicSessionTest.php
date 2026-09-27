<?php

use App\Enums\AcademicSessionStatus;
use App\Enums\Role;
use App\Models\AcademicSession;
use App\Models\Term;

test('listing sessions requires authentication', function () {
    $this->getJson('/api/v1/academic-sessions')->assertUnauthorized();
});

test('listing sessions requires the view permission', function () {
    asUser(userWithRole(Role::STUDENT))->getJson('/api/v1/academic-sessions')->assertForbidden();
});

test('a session is created as upcoming and can be listed', function () {
    asUser(userWithRole(Role::ADMIN))
        ->postJson('/api/v1/academic-sessions', [
            'name' => '2026/2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-08-31',
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', '2026/2027')
        ->assertJsonPath('data.status', AcademicSessionStatus::UPCOMING->value)
        ->assertJsonPath('data.is_current', false);

    asUser(userWithRole(Role::ADMIN))
        ->getJson('/api/v1/academic-sessions')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', '2026/2027');
});

test('a session name is normalised before it is checked for uniqueness', function () {
    asUser(userWithRole(Role::ADMIN))
        ->postJson('/api/v1/academic-sessions', [
            'name' => '2026/2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-08-31',
        ])
        ->assertCreated();

    // "2026 - 2027" normalises to the same string as the stored name. If the check ran
    // before the normalisation, this would pass validation and then fail on the unique
    // index as a 500 instead of a 422.
    asUser(userWithRole(Role::ADMIN))
        ->postJson('/api/v1/academic-sessions', [
            'name' => '2026 - 2027',
            'start_date' => '2027-09-01',
            'end_date' => '2028-08-31',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    expect(AcademicSession::count())->toBe(1);
});

test('a session must end after it starts', function () {
    asUser(userWithRole(Role::ADMIN))
        ->postJson('/api/v1/academic-sessions', [
            'name' => '2026/2027',
            'start_date' => '2027-08-31',
            'end_date' => '2026-09-01',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['end_date']);
});

test('a session name cannot be reused', function () {
    AcademicSession::factory()->create(['name' => '2026/2027']);

    asUser(userWithRole(Role::ADMIN))
        ->postJson('/api/v1/academic-sessions', [
            'name' => '2026/2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-08-31',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('a session status cannot be set by the caller', function () {
    // Status is derived, never supplied. Allowing it here would let a client create a
    // second current session and receive an integrity error rendered as a 500.
    asUser(userWithRole(Role::ADMIN))
        ->postJson('/api/v1/academic-sessions', [
            'name' => '2026/2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-08-31',
            'status' => 'ACTIVE',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', AcademicSessionStatus::UPCOMING->value);
});

test('activating a session completes the one that was current', function () {
    $current = AcademicSession::factory()->active()->create(['name' => '2025/2026']);
    $next = AcademicSession::factory()->create(['name' => '2026/2027']);

    asUser(userWithRole(Role::ADMIN))
        ->postJson("/api/v1/academic-sessions/{$next->id}/activate")
        ->assertOk()
        ->assertJsonPath('data.status', AcademicSessionStatus::ACTIVE->value)
        ->assertJsonPath('data.is_current', true);

    expect($current->fresh()->status)->toBe(AcademicSessionStatus::COMPLETED);
    expect(AcademicSession::query()->current()->count())->toBe(1);
});

test('activating the session that is already current is a no op', function () {
    $current = AcademicSession::factory()->active()->create();

    asUser(userWithRole(Role::ADMIN))
        ->postJson("/api/v1/academic-sessions/{$current->id}/activate")
        ->assertOk()
        ->assertJsonPath('data.is_current', true);

    expect($current->fresh()->status)->toBe(AcademicSessionStatus::ACTIVE);
});

test('a completed session cannot be activated', function () {
    $completed = AcademicSession::factory()->completed()->create();

    asUser(userWithRole(Role::ADMIN))
        ->postJson("/api/v1/academic-sessions/{$completed->id}/activate")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'A completed academic session cannot be activated. Create a new session instead.');
});

test('a completed session cannot be amended', function () {
    $completed = AcademicSession::factory()->completed()->create();

    asUser(userWithRole(Role::ADMIN))
        ->patchJson("/api/v1/academic-sessions/{$completed->id}", [
            'name' => 'Renamed',
            'start_date' => '2026-09-01',
            'end_date' => '2027-08-31',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'A completed academic session cannot be modified. Its record is kept for history.');

    expect($completed->fresh()->name)->not->toBe('Renamed');
});

test('a session can be re-saved with its own name', function () {
    $session = AcademicSession::factory()->create(['name' => '2026/2027']);

    asUser(userWithRole(Role::ADMIN))
        ->patchJson("/api/v1/academic-sessions/{$session->id}", [
            'name' => '2026/2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-08-31',
        ])
        ->assertOk();
});

test('an unused upcoming session can be deleted', function () {
    $session = AcademicSession::factory()->create();

    asUser(userWithRole(Role::SUPER_ADMIN))
        ->deleteJson("/api/v1/academic-sessions/{$session->id}")
        ->assertOk();

    $this->assertDatabaseMissing('academic_sessions', ['id' => $session->id]);
});

test('the current session cannot be deleted', function () {
    $current = AcademicSession::factory()->active()->create();

    asUser(userWithRole(Role::SUPER_ADMIN))
        ->deleteJson("/api/v1/academic-sessions/{$current->id}")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The current academic session cannot be deleted. Activate another session first.');
});

test('a completed session cannot be deleted', function () {
    $completed = AcademicSession::factory()->completed()->create();

    asUser(userWithRole(Role::SUPER_ADMIN))
        ->deleteJson("/api/v1/academic-sessions/{$completed->id}")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'A completed academic session cannot be deleted. It is kept as history.');
});

test('a session with terms cannot be deleted', function () {
    $session = AcademicSession::factory()->create();
    Term::factory()->create(['academic_session_id' => $session->id]);

    asUser(userWithRole(Role::SUPER_ADMIN))
        ->deleteJson("/api/v1/academic-sessions/{$session->id}")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This academic session already has terms and cannot be deleted. Delete its terms first.');
});

test('only a super administrator may delete a session', function () {
    $session = AcademicSession::factory()->create();

    // An administrator runs the school day to day but must not be able to erase academic
    // history, so the destructive permission is withheld from them.
    asUser(userWithRole(Role::ADMIN))
        ->deleteJson("/api/v1/academic-sessions/{$session->id}")
        ->assertForbidden();

    // asUser() clears the previous user's token and resolved account, so this request is
    // genuinely made as the super administrator rather than as the administrator above.
    asUser(userWithRole(Role::SUPER_ADMIN))
        ->deleteJson("/api/v1/academic-sessions/{$session->id}")
        ->assertOk();
});

test('sessions can be filtered by status and searched', function () {
    AcademicSession::factory()->active()->create(['name' => '2025/2026']);
    AcademicSession::factory()->create(['name' => '2027/2028']);

    asUser(userWithRole(Role::ADMIN))
        ->getJson('/api/v1/academic-sessions?status=ACTIVE')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', '2025/2026');

    asUser(userWithRole(Role::ADMIN))
        ->getJson('/api/v1/academic-sessions?search=2027')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', '2027/2028');
});

test('sessions are listed most recent first', function () {
    AcademicSession::factory()->create(['name' => '2024/2025', 'start_date' => '2024-09-01', 'end_date' => '2025-08-31']);
    AcademicSession::factory()->create(['name' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-08-31']);
    AcademicSession::factory()->create(['name' => '2025/2026', 'start_date' => '2025-09-01', 'end_date' => '2026-08-31']);

    asUser(userWithRole(Role::ADMIN))
        ->getJson('/api/v1/academic-sessions')
        ->assertOk()
        ->assertJsonPath('data.0.name', '2026/2027')
        ->assertJsonPath('data.2.name', '2024/2025');
});

test('a missing session returns a json 404', function () {
    asUser(userWithRole(Role::ADMIN))
        ->getJson('/api/v1/academic-sessions/999999')
        ->assertNotFound()
        ->assertJsonPath('message', 'Resource not found.');
});

test('the paginated list keeps page metadata beside the data rather than inside it', function () {
    AcademicSession::factory()->count(20)->create();

    asUser(userWithRole(Role::ADMIN))
        ->getJson('/api/v1/academic-sessions?per_page=5')
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonStructure([
            'data',
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'last_page', 'per_page', 'from', 'to', 'total'],
        ])
        // The nested "data.data" shape Laravel produces by default would mean a client
        // needs a second code path for every list endpoint in the API.
        ->assertJsonMissingPath('data.data');
});
