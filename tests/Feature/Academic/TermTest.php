<?php

use App\Enums\Role;
use App\Enums\TermStatus;
use App\Models\AcademicSession;
use App\Models\Term;

test('listing the terms of a session requires authentication', function () {
    $session = AcademicSession::factory()->create();

    $this->getJson("/api/v1/academic-sessions/{$session->id}/terms")->assertUnauthorized();
});

test('listing terms requires the view permission', function () {
    $session = AcademicSession::factory()->create();

    asUser(userWithRole(Role::STUDENT))
        ->getJson("/api/v1/academic-sessions/{$session->id}/terms")
        ->assertForbidden();
});

test('a term is created as upcoming inside its session', function () {
    $session = AcademicSession::factory()->create([
        'start_date' => '2026-09-01',
        'end_date' => '2027-08-31',
    ]);

    asUser(userWithRole(Role::REGISTRAR))
        ->postJson("/api/v1/academic-sessions/{$session->id}/terms", [
            'name' => 'First Term',
            'term_number' => 1,
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-18',
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'First Term')
        ->assertJsonPath('data.term_number', 1)
        ->assertJsonPath('data.academic_session_id', $session->id)
        ->assertJsonPath('data.status', TermStatus::UPCOMING->value)
        ->assertJsonPath('data.is_current', false);

    $this->assertDatabaseHas('terms', [
        'academic_session_id' => $session->id,
        'term_number' => 1,
    ]);
});

test('a term must fall inside the dates of its session', function () {
    $session = AcademicSession::factory()->create([
        'name' => '2026/2027',
        'start_date' => '2026-09-01',
        'end_date' => '2027-08-31',
    ]);

    // Starting before the session opens would place the term in a year it does not belong
    // to, and later modules read a term's dates to decide which year a mark belongs to.
    asUser(userWithRole(Role::REGISTRAR))
        ->postJson("/api/v1/academic-sessions/{$session->id}/terms", [
            'name' => 'First Term',
            'term_number' => 1,
            'start_date' => '2026-08-01',
            'end_date' => '2026-12-18',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['start_date', 'end_date']);

    asUser(userWithRole(Role::REGISTRAR))
        ->postJson("/api/v1/academic-sessions/{$session->id}/terms", [
            'name' => 'Third Term',
            'term_number' => 3,
            'start_date' => '2027-05-01',
            'end_date' => '2027-09-30',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['start_date', 'end_date']);

    expect(Term::count())->toBe(0);
});

test('a term must end after it starts', function () {
    $session = AcademicSession::factory()->create([
        'start_date' => '2026-09-01',
        'end_date' => '2027-08-31',
    ]);

    asUser(userWithRole(Role::REGISTRAR))
        ->postJson("/api/v1/academic-sessions/{$session->id}/terms", [
            'name' => 'First Term',
            'term_number' => 1,
            'start_date' => '2026-12-18',
            'end_date' => '2026-09-01',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['end_date']);
});

test('a term number cannot be reused inside the same session', function () {
    $session = AcademicSession::factory()->create([
        'start_date' => '2026-09-01',
        'end_date' => '2027-08-31',
    ]);

    Term::factory()->forSession($session, 1)->create();

    asUser(userWithRole(Role::REGISTRAR))
        ->postJson("/api/v1/academic-sessions/{$session->id}/terms", [
            'name' => 'Another First Term',
            'term_number' => 1,
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-18',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['term_number']);
});

test('the same term number may be reused in a different session', function () {
    // "First Term" is a position in a year, not a global label, so uniqueness is scoped to
    // the session and the number restarts each year.
    $thisYear = AcademicSession::factory()->create([
        'name' => '2026/2027',
        'start_date' => '2026-09-01',
        'end_date' => '2027-08-31',
    ]);
    $nextYear = AcademicSession::factory()->create([
        'name' => '2027/2028',
        'start_date' => '2027-09-01',
        'end_date' => '2028-08-31',
    ]);

    Term::factory()->forSession($thisYear, 1)->create();

    asUser(userWithRole(Role::REGISTRAR))
        ->postJson("/api/v1/academic-sessions/{$nextYear->id}/terms", [
            'name' => 'First Term',
            'term_number' => 1,
            'start_date' => '2027-09-01',
            'end_date' => '2027-12-17',
        ])
        ->assertCreated();
});

test('a term status cannot be set by the caller', function () {
    $session = AcademicSession::factory()->create([
        'start_date' => '2026-09-01',
        'end_date' => '2027-08-31',
    ]);

    asUser(userWithRole(Role::REGISTRAR))
        ->postJson("/api/v1/academic-sessions/{$session->id}/terms", [
            'name' => 'First Term',
            'term_number' => 1,
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-18',
            'status' => 'ACTIVE',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', TermStatus::UPCOMING->value);
});

test('terms of a session are listed in term number order', function () {
    $session = AcademicSession::factory()->create([
        'start_date' => '2026-09-01',
        'end_date' => '2027-08-31',
    ]);

    Term::factory()->forSession($session, 3)->create();
    Term::factory()->forSession($session, 1)->create();
    Term::factory()->forSession($session, 2)->create();

    asUser(userWithRole(Role::STAFF))
        ->getJson("/api/v1/academic-sessions/{$session->id}/terms")
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.term_number', 1)
        ->assertJsonPath('data.1.term_number', 2)
        ->assertJsonPath('data.2.term_number', 3);
});

test('terms are only listed under the session they belong to', function () {
    $one = AcademicSession::factory()->create();
    $two = AcademicSession::factory()->create();

    Term::factory()->forSession($one, 1)->create();
    Term::factory()->forSession($two, 1)->create();

    asUser(userWithRole(Role::STAFF))
        ->getJson("/api/v1/academic-sessions/{$one->id}/terms")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.academic_session_id', $one->id);
});

test('a term is readable on its own flat url with its session', function () {
    $session = AcademicSession::factory()->create(['name' => '2026/2027']);
    $term = Term::factory()->forSession($session, 1)->create();

    asUser(userWithRole(Role::STAFF))
        ->getJson("/api/v1/terms/{$term->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $term->id)
        ->assertJsonPath('data.academic_session.id', $session->id)
        ->assertJsonPath('data.academic_session.name', '2026/2027');
});

test('a term can be renamed and its dates moved', function () {
    $session = AcademicSession::factory()->create([
        'start_date' => '2026-09-01',
        'end_date' => '2027-08-31',
    ]);
    $term = Term::factory()->forSession($session, 1)->create([
        'name' => 'First Term',
        'start_date' => '2026-09-01',
        'end_date' => '2026-12-18',
    ]);

    asUser(userWithRole(Role::REGISTRAR))
        ->patchJson("/api/v1/terms/{$term->id}", [
            'name' => 'First Term (Revised)',
            'term_number' => 1,
            'start_date' => '2026-09-08',
            'end_date' => '2026-12-20',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'First Term (Revised)')
        ->assertJsonPath('data.start_date', '2026-09-08');
});

test('a term cannot be re-saved with a term number another term already holds', function () {
    $session = AcademicSession::factory()->create([
        'start_date' => '2026-09-01',
        'end_date' => '2027-08-31',
    ]);
    Term::factory()->forSession($session, 1)->create();
    $second = Term::factory()->forSession($session, 2)->create();

    asUser(userWithRole(Role::REGISTRAR))
        ->patchJson("/api/v1/terms/{$second->id}", [
            'name' => 'Second Term',
            'term_number' => 1,
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-18',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['term_number']);
});

test('a term can be re-saved with its own term number', function () {
    $session = AcademicSession::factory()->create([
        'start_date' => '2026-09-01',
        'end_date' => '2027-08-31',
    ]);
    $term = Term::factory()->forSession($session, 1)->create([
        'name' => 'First Term',
    ]);

    asUser(userWithRole(Role::REGISTRAR))
        ->patchJson("/api/v1/terms/{$term->id}", [
            'name' => 'First Term',
            'term_number' => 1,
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-18',
        ])
        ->assertOk();
});

test('a term cannot be moved to a different session', function () {
    $from = AcademicSession::factory()->create([
        'start_date' => '2026-09-01',
        'end_date' => '2027-08-31',
    ]);
    $to = AcademicSession::factory()->create([
        'start_date' => '2027-09-01',
        'end_date' => '2028-08-31',
    ]);
    $term = Term::factory()->forSession($from, 1)->create();

    asUser(userWithRole(Role::SUPER_ADMIN))
        ->patchJson("/api/v1/terms/{$term->id}", [
            'name' => 'First Term',
            'term_number' => 1,
            'start_date' => '2027-09-01',
            'end_date' => '2027-12-17',
            'academic_session_id' => $to->id,
        ])
        ->assertUnprocessable();

    expect($term->fresh()->academic_session_id)->toBe($from->id);
});

test('a completed term cannot be amended', function () {
    $session = AcademicSession::factory()->create([
        'start_date' => '2026-09-01',
        'end_date' => '2027-08-31',
    ]);
    $term = Term::factory()->forSession($session, 1)->completed()->create();

    asUser(userWithRole(Role::REGISTRAR))
        ->patchJson("/api/v1/terms/{$term->id}", [
            'name' => 'Renamed',
            'term_number' => 1,
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-18',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'A completed term cannot be modified. Its record is kept for history.');

    expect($term->fresh()->name)->not->toBe('Renamed');
});

test('activating a term completes the one that was current', function () {
    $session = AcademicSession::factory()->active()->create([
        'start_date' => '2026-09-01',
        'end_date' => '2027-08-31',
    ]);
    $first = Term::factory()->forSession($session, 1)->active()->create();
    $second = Term::factory()->forSession($session, 2)->create([
        'start_date' => '2027-01-11',
        'end_date' => '2027-04-09',
    ]);

    asUser(userWithRole(Role::ADMIN))
        ->postJson("/api/v1/terms/{$second->id}/activate")
        ->assertOk()
        ->assertJsonPath('data.status', TermStatus::ACTIVE->value)
        ->assertJsonPath('data.is_current', true);

    expect($first->fresh()->status)->toBe(TermStatus::COMPLETED);
    expect(Term::query()->current()->count())->toBe(1);
});

test('a term of a session that is not current cannot be activated', function () {
    $lastYear = AcademicSession::factory()->completed()->create([
        'name' => '2025/2026',
        'start_date' => '2025-09-01',
        'end_date' => '2026-08-31',
    ]);
    AcademicSession::factory()->active()->create([
        'name' => '2026/2027',
        'start_date' => '2026-09-01',
        'end_date' => '2027-08-31',
    ]);

    $oldTerm = Term::factory()->forSession($lastYear, 1)->create();

    // A current term that disagreed with the current session would be read independently
    // by later modules, so the contradiction has to be refused at the point it is created.
    asUser(userWithRole(Role::ADMIN))
        ->postJson("/api/v1/terms/{$oldTerm->id}/activate")
        ->assertUnprocessable()
        ->assertJsonPath(
            'message',
            'This term belongs to 2025/2026, which is not the current academic session. Only a term of the current session can be activated.'
        );
});

test('a term cannot be activated when there is no current session', function () {
    $session = AcademicSession::factory()->create([
        'start_date' => '2026-09-01',
        'end_date' => '2027-08-31',
    ]);
    $term = Term::factory()->forSession($session, 1)->create();

    asUser(userWithRole(Role::ADMIN))
        ->postJson("/api/v1/terms/{$term->id}/activate")
        ->assertUnprocessable()
        ->assertJsonPath(
            'message',
            'There is no current academic session. Activate an academic session before activating a term.'
        );
});

test('a completed term cannot be activated', function () {
    $session = AcademicSession::factory()->active()->create([
        'start_date' => '2026-09-01',
        'end_date' => '2027-08-31',
    ]);
    $term = Term::factory()->forSession($session, 1)->completed()->create();

    asUser(userWithRole(Role::ADMIN))
        ->postJson("/api/v1/terms/{$term->id}/activate")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'A completed term cannot be activated. Create a new term instead.');
});

test('an unused upcoming term can be deleted', function () {
    $session = AcademicSession::factory()->create([
        'start_date' => '2026-09-01',
        'end_date' => '2027-08-31',
    ]);
    $term = Term::factory()->forSession($session, 2)->create();

    asUser(userWithRole(Role::SUPER_ADMIN))
        ->deleteJson("/api/v1/terms/{$term->id}")
        ->assertOk();

    $this->assertDatabaseMissing('terms', ['id' => $term->id]);
});

test('the current term cannot be deleted', function () {
    $session = AcademicSession::factory()->active()->create([
        'start_date' => '2026-09-01',
        'end_date' => '2027-08-31',
    ]);
    $term = Term::factory()->forSession($session, 1)->active()->create();

    asUser(userWithRole(Role::SUPER_ADMIN))
        ->deleteJson("/api/v1/terms/{$term->id}")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The current term cannot be deleted. Activate another term first.');
});

test('a completed term cannot be deleted', function () {
    $session = AcademicSession::factory()->create([
        'start_date' => '2026-09-01',
        'end_date' => '2027-08-31',
    ]);
    $term = Term::factory()->forSession($session, 1)->completed()->create();

    asUser(userWithRole(Role::SUPER_ADMIN))
        ->deleteJson("/api/v1/terms/{$term->id}")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'A completed term cannot be deleted. It is kept as history.');
});

test('only a super administrator may delete a term', function () {
    $session = AcademicSession::factory()->create([
        'start_date' => '2026-09-01',
        'end_date' => '2027-08-31',
    ]);
    $term = Term::factory()->forSession($session, 2)->create();

    asUser(userWithRole(Role::ADMIN))
        ->deleteJson("/api/v1/terms/{$term->id}")
        ->assertForbidden();

    asUser(userWithRole(Role::SUPER_ADMIN))
        ->deleteJson("/api/v1/terms/{$term->id}")
        ->assertOk();
});

test('a missing term returns a json 404', function () {
    asUser(userWithRole(Role::STAFF))
        ->getJson('/api/v1/terms/999999')
        ->assertNotFound()
        ->assertJsonPath('message', 'Resource not found.');
});

test('term lists are paginated with the metadata beside the data', function () {
    $session = AcademicSession::factory()->create([
        'start_date' => '2020-09-01',
        'end_date' => '2027-08-31',
    ]);

    foreach (range(1, 5) as $number) {
        Term::factory()->forSession($session, $number)->create();
    }

    asUser(userWithRole(Role::STAFF))
        ->getJson("/api/v1/academic-sessions/{$session->id}/terms?per_page=2")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure([
            'data',
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'last_page', 'per_page', 'from', 'to', 'total'],
        ])
        ->assertJsonPath('meta.total', 5)
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonMissingPath('data.data');
});
