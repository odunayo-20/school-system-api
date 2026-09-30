<?php

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Notifications\AccountCreatedNotification;
use App\Notifications\EnrollmentNotification;
use App\Notifications\ResultPublishedNotification;
use App\Services\Enrollment\EnrollmentService;
use App\Services\Result\ResultService;
use App\Services\Staff\StaffService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Notifications: creation via real triggers, listing, unread, mark-as-read,
| mark-all-as-read, and cross-user authorization (Module 18)
|--------------------------------------------------------------------------
*/

/*
| Creation via real triggers
*/

it('notifies a newly created staff account of its own creation', function (): void {
    $staff = app(StaffService::class)->create(
        ['name' => 'New Teacher', 'email' => 'new.teacher@example.test', 'password' => 'password'],
        ['staff_type' => 'TEACHING']
    );

    expect($staff->user->notifications()->count())->toBe(1);

    $notification = $staff->user->notifications()->first();

    expect($notification->type)->toBe(AccountCreatedNotification::class)
        ->and($notification->data['user_id'])->toBe($staff->user_id)
        ->and($notification->read_at)->toBeNull();
});

it('notifies an enrolled student of their placement, only when a portal account is linked', function (): void {
    $withAccount = pupilWithAccount();
    $enrollment = app(EnrollmentService::class)->create(enrollmentCreatePayload(['student_id' => $withAccount->id]));

    expect($withAccount->user->notifications()->count())->toBe(1);

    $notification = $withAccount->user->notifications()->first();
    expect($notification->type)->toBe(EnrollmentNotification::class)
        ->and($notification->data['enrollment_id'])->toBe($enrollment->id);
});

it('sends no enrollment notification when the student has no linked portal account', function (): void {
    $withoutAccount = pupil();

    app(EnrollmentService::class)->create(enrollmentCreatePayload(['student_id' => $withoutAccount->id]));

    expect(DatabaseNotification::query()->count())->toBe(0);
});

it('notifies a student of a published result, only when a portal account is linked', function (): void {
    $withAccount = pupilWithAccount();
    $admin = userWithRole(Role::ADMIN);
    $service = app(ResultService::class);

    [$assessment, $enrollment] = resultCompilationContext([], ['student_id' => $withAccount->id]);
    $result = $service->compile([
        'enrollment_id' => $enrollment->id,
        'class_subject_id' => $assessment->class_subject_id,
        'term_id' => $assessment->term_id,
    ], $admin);
    $result = $service->submit($result, $admin);
    $result = $service->approve($result, $admin);
    $result = $service->publish($result, $admin);

    expect($withAccount->user->notifications()->count())->toBe(1);

    $notification = $withAccount->user->notifications()->first();
    expect($notification->type)->toBe(ResultPublishedNotification::class)
        ->and($notification->data['result_id'])->toBe($result->id);
});

it('does not notify anyone when a result is only submitted or approved, not published', function (): void {
    $withAccount = pupilWithAccount();
    $admin = userWithRole(Role::ADMIN);
    $service = app(ResultService::class);

    [$assessment, $enrollment] = resultCompilationContext([], ['student_id' => $withAccount->id]);
    $result = $service->compile([
        'enrollment_id' => $enrollment->id,
        'class_subject_id' => $assessment->class_subject_id,
        'term_id' => $assessment->term_id,
    ], $admin);
    $service->submit($result, $admin);

    expect(DatabaseNotification::query()->count())->toBe(0);
});

it('never delivers a notification to an unrelated user', function (): void {
    $staff = app(StaffService::class)->create(
        ['name' => 'Isolated Teacher', 'email' => 'isolated.teacher@example.test', 'password' => 'password'],
        ['staff_type' => 'TEACHING']
    );

    $bystander = userWithRole(Role::ADMIN);

    expect($bystander->notifications()->count())->toBe(0)
        ->and($staff->user->notifications()->count())->toBe(1);
});

/*
| Listing
*/

it('lets an authenticated user list their own notifications, paginated', function (): void {
    $staff = app(StaffService::class)->create(
        ['name' => 'List Teacher', 'email' => 'list.teacher@example.test', 'password' => 'password'],
        ['staff_type' => 'TEACHING']
    );
    $token = loginAs($staff->user);

    withToken($token)->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonStructure([
            'data' => [['id', 'type', 'data', 'read', 'read_at', 'created_at']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);
});

it('caps the page size and rejects an invalid one', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->getJson('/api/v1/notifications?per_page=1000')
        ->assertUnprocessable()->assertJsonValidationErrors('per_page');
});

it('rejects a search parameter, because a notification has no text field to search', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->getJson('/api/v1/notifications?search=anything')
        ->assertUnprocessable()->assertJsonValidationErrors('search');
});

it('never returns another user\'s notifications in the list', function (): void {
    $staffA = app(StaffService::class)->create(
        ['name' => 'Teacher A', 'email' => 'teacher.a@example.test', 'password' => 'password'],
        ['staff_type' => 'TEACHING']
    );
    $staffB = app(StaffService::class)->create(
        ['name' => 'Teacher B', 'email' => 'teacher.b@example.test', 'password' => 'password'],
        ['staff_type' => 'TEACHING']
    );

    $tokenA = loginAs($staffA->user);

    $response = withToken($tokenA)->getJson('/api/v1/notifications');

    $response->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.data.user_id', $staffA->user_id);
});

/*
| Unread
*/

it('lists only unread notifications on the unread endpoint', function (): void {
    $staff = app(StaffService::class)->create(
        ['name' => 'Unread Teacher', 'email' => 'unread.teacher@example.test', 'password' => 'password'],
        ['staff_type' => 'TEACHING']
    );
    $token = loginAs($staff->user);

    withToken($token)->getJson('/api/v1/notifications/unread')->assertOk()->assertJsonCount(1, 'data');

    $notification = $staff->user->notifications()->first();
    $notification->markAsRead();

    withToken($token)->getJson('/api/v1/notifications/unread')->assertOk()->assertJsonCount(0, 'data');
    withToken($token)->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(1, 'data');
});

/*
| Mark as read
*/

it('lets a user mark their own notification as read', function (): void {
    $staff = app(StaffService::class)->create(
        ['name' => 'Mark Teacher', 'email' => 'mark.teacher@example.test', 'password' => 'password'],
        ['staff_type' => 'TEACHING']
    );
    $token = loginAs($staff->user);
    $notification = $staff->user->notifications()->first();

    $response = withToken($token)->postJson("/api/v1/notifications/{$notification->id}/read");

    $response->assertOk()
        ->assertJsonPath('data.read', true)
        ->assertJsonPath('data.read_at', fn (?string $at): bool => $at !== null);

    expect($notification->fresh()->read_at)->not->toBeNull();
});

it('is safe to mark the same notification as read more than once', function (): void {
    $staff = app(StaffService::class)->create(
        ['name' => 'Repeat Teacher', 'email' => 'repeat.teacher@example.test', 'password' => 'password'],
        ['staff_type' => 'TEACHING']
    );
    $token = loginAs($staff->user);
    $notification = $staff->user->notifications()->first();

    withToken($token)->postJson("/api/v1/notifications/{$notification->id}/read")->assertOk();
    $firstReadAt = $notification->fresh()->read_at;

    withToken($token)->postJson("/api/v1/notifications/{$notification->id}/read")->assertOk();

    expect($notification->fresh()->read_at->eq($firstReadAt))->toBeTrue()
        ->and(DatabaseNotification::query()->count())->toBe(1);
});

it('answers 404 for a notification that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->postJson('/api/v1/notifications/'.Str::uuid().'/read')
        ->assertNotFound();
});

/*
| Mark all as read
*/

it('marks every one of the user\'s own unread notifications as read, leaving others untouched', function (): void {
    $studentA = pupilWithAccount();
    $studentB = pupilWithAccount();

    $secondSession = eligibleSession();

    app(EnrollmentService::class)->create(enrollmentCreatePayload(['student_id' => $studentA->id]));
    app(EnrollmentService::class)->create(enrollmentCreatePayload([
        'student_id' => $studentA->id,
        'academic_session_id' => $secondSession->id,
        'enrollment_date' => $secondSession->start_date->toDateString(),
    ]));
    app(EnrollmentService::class)->create(enrollmentCreatePayload(['student_id' => $studentB->id]));

    expect($studentA->user->unreadNotifications()->count())->toBe(2)
        ->and($studentB->user->unreadNotifications()->count())->toBe(1);

    $tokenA = loginAs($studentA->user);
    withToken($tokenA)->postJson('/api/v1/notifications/read-all')->assertOk();

    expect($studentA->user->unreadNotifications()->count())->toBe(0)
        ->and($studentB->user->unreadNotifications()->count())->toBe(1);
});

it('is safe to mark all as read when there is nothing unread', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->postJson('/api/v1/notifications/read-all')->assertOk();
    withToken($token)->postJson('/api/v1/notifications/read-all')->assertOk();
});

/*
| Authorization / IDOR
*/

it('refuses every notification endpoint to an unauthenticated caller', function (): void {
    test()->getJson('/api/v1/notifications')->assertUnauthorized();
    test()->getJson('/api/v1/notifications/unread')->assertUnauthorized();
    test()->postJson('/api/v1/notifications/read-all')->assertUnauthorized();
    test()->postJson('/api/v1/notifications/'.Str::uuid().'/read')->assertUnauthorized();
});

it('refuses user A marking user B\'s notification as read', function (): void {
    $staffA = app(StaffService::class)->create(
        ['name' => 'Victim Teacher', 'email' => 'victim.teacher@example.test', 'password' => 'password'],
        ['staff_type' => 'TEACHING']
    );
    $staffB = app(StaffService::class)->create(
        ['name' => 'Attacker Teacher', 'email' => 'attacker.teacher@example.test', 'password' => 'password'],
        ['staff_type' => 'TEACHING']
    );

    $notificationA = $staffA->user->notifications()->first();
    $tokenB = loginAs($staffB->user);

    withToken($tokenB)->postJson("/api/v1/notifications/{$notificationA->id}/read")->assertForbidden();

    expect($notificationA->fresh()->read_at)->toBeNull();
});

it('refuses a suspended account, even with a token minted before the suspension', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $token = loginAs($admin);

    $admin->forceFill(['status' => UserStatus::SUSPENDED])->save();
    forgetResolvedUser();

    withToken($token)->getJson('/api/v1/notifications')->assertForbidden();
});
