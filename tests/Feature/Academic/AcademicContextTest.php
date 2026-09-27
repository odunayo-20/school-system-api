<?php

use App\Enums\AcademicSessionStatus;
use App\Enums\Role;
use App\Enums\TermStatus;
use App\Models\AcademicSession;
use App\Models\School;
use App\Models\Term;
use App\Services\Academic\AcademicSessionService;

test('reading the academic context requires authentication', function () {
    $this->getJson('/api/v1/academic-context')->assertUnauthorized();
});

test('reading the academic context requires a permission', function () {
    // Students are refused here even though the context is a read: their own session and
    // term arrive with the student module, scoped to their own enrollment.
    asUser(userWithRole(Role::STUDENT))
        ->getJson('/api/v1/academic-context')
        ->assertForbidden();
});

test('an unconfigured school reports every part as null', function () {
    asUser(userWithRole(Role::STAFF))
        ->getJson('/api/v1/academic-context')
        ->assertOk()
        ->assertJsonPath('data.school', null)
        ->assertJsonPath('data.session', null)
        ->assertJsonPath('data.term', null)
        ->assertJsonPath(
            'message',
            'The school academic setup is incomplete. A profile, a current session and a current term are all required.'
        );
});

test('a school with no session reports the profile and nothing else', function () {
    School::factory()->active()->create(['name' => 'Greenfield School']);

    asUser(userWithRole(Role::STAFF))
        ->getJson('/api/v1/academic-context')
        ->assertOk()
        ->assertJsonPath('data.school.name', 'Greenfield School')
        ->assertJsonPath('data.session', null)
        ->assertJsonPath('data.term', null);
});

test('a session with no active term reports the session but no term', function () {
    School::factory()->active()->create();
    AcademicSession::factory()->active()->create(['name' => '2026/2027']);

    // A session that has opened but whose first term has not been activated yet is an
    // ordinary state in the first days of a school year, not a configuration error, and the
    // client needs to be able to say so precisely.
    asUser(userWithRole(Role::STAFF))
        ->getJson('/api/v1/academic-context')
        ->assertOk()
        ->assertJsonPath('data.session.name', '2026/2027')
        ->assertJsonPath('data.term', null);
});

test('a fully configured school reports the profile, session and term together', function () {
    [$school, $session, $term] = configuredSchool('2026/2027');

    $response = asUser(userWithRole(Role::STAFF))
        ->getJson('/api/v1/academic-context')
        ->assertOk()
        ->assertJsonPath('message', 'Current academic context.')
        ->assertJsonPath('data.school.id', $school->getKey())
        ->assertJsonPath('data.school.status', $school->status->value)
        ->assertJsonPath('data.session.id', $session->getKey())
        ->assertJsonPath('data.session.name', '2026/2027')
        ->assertJsonPath('data.session.status', AcademicSessionStatus::ACTIVE->value)
        ->assertJsonPath('data.term.id', $term->getKey())
        ->assertJsonPath('data.term.academic_session_id', $session->getKey())
        ->assertJsonPath('data.term.status', TermStatus::ACTIVE->value);

    // One read replaces three: the point of the endpoint is that a client does not have to
    // call the school, session and term endpoints separately to draw a header.
    expect(array_keys($response->json('data')))->toBe(['school', 'session', 'term']);
});

test('the context reports the current session and term rather than the newest ones', function () {
    School::factory()->active()->create();

    $current = AcademicSession::factory()->active()->create(['name' => '2026/2027']);
    $upcoming = AcademicSession::factory()->create(['name' => '2027/2028']);
    $currentTerm = Term::factory()->forSession($current, 1)->active()->create();
    Term::factory()->forSession($upcoming, 1)->create();

    asUser(userWithRole(Role::STAFF))
        ->getJson('/api/v1/academic-context')
        ->assertOk()
        ->assertJsonPath('data.session.name', '2026/2027')
        ->assertJsonPath('data.term.id', $currentTerm->getKey());
});

test('a completed term is not reported as current', function () {
    School::factory()->active()->create();
    $session = AcademicSession::factory()->active()->create();

    Term::factory()->forSession($session, 1)->completed()->create();
    $current = Term::factory()->forSession($session, 2)->active()->create();

    asUser(userWithRole(Role::STAFF))
        ->getJson('/api/v1/academic-context')
        ->assertOk()
        ->assertJsonPath('data.term.id', $current->getKey());
});

test('the context is read fresh rather than memoised', function () {
    School::factory()->active()->create();
    $session = AcademicSession::factory()->active()->create();
    $term = Term::factory()->forSession($session, 1)->create();

    expect($term->isCurrent())->toBeFalse();

    // The context deliberately does not cache. A read that followed this write in the same
    // process must see the new state, otherwise any module that activates a term and then
    // reports the context would report the term that was current before the activation.
    $term->status = TermStatus::ACTIVE;
    $term->save();

    asUser(userWithRole(Role::STAFF))
        ->getJson('/api/v1/academic-context')
        ->assertOk()
        ->assertJsonPath('data.term.id', $term->getKey())
        ->assertJsonPath('data.term.status', TermStatus::ACTIVE->value);
});

test('a newly activated session is reflected in the context', function () {
    School::factory()->active()->create();
    $old = AcademicSession::factory()->active()->create(['name' => '2025/2026']);
    $new = AcademicSession::factory()->create(['name' => '2026/2027']);
    Term::factory()->forSession($new, 1)->create();

    expect(AcademicSession::findCurrent()->getKey())->toBe($old->getKey());

    app(AcademicSessionService::class)->activate($new);

    asUser(userWithRole(Role::STAFF))
        ->getJson('/api/v1/academic-context')
        ->assertOk()
        ->assertJsonPath('data.session.name', '2026/2027')
        ->assertJsonPath('data.session.status', AcademicSessionStatus::ACTIVE->value);
});

test('staff and registrars may both read the context', function () {
    configuredSchool('2026/2027');

    // Read access to the academic calendar is what a timetable or a register needs, and it
    // is granted to every non student role.
    asUser(userWithRole(Role::STAFF))
        ->getJson('/api/v1/academic-context')
        ->assertOk();

    asUser(userWithRole(Role::REGISTRAR))
        ->getJson('/api/v1/academic-context')
        ->assertOk();

    asUser(userWithRole(Role::ADMIN))
        ->getJson('/api/v1/academic-context')
        ->assertOk();
});
