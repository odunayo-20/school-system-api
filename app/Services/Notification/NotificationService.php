<?php

namespace App\Services\Notification;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Reading and acknowledging the authenticated user's own database notifications.
 *
 * THIS DOES NOT SEND NOTIFICATIONS. Dispatching one is each triggering module's own concern -
 * StaffService, EnrollmentService and ResultService already call `$user->notify(...)` directly
 * at the moment their own domain event genuinely happens, the same "the module that knows the
 * fact writes it" pattern every service in this project already follows. This class exists
 * only for the other half: letting a user list, count and acknowledge what has already been
 * written to their own notifications table.
 *
 * EVERY METHOD IS SCOPED TO THE PASSED-IN USER'S OWN NOTIFICATIONS, never a global query - the
 * identical "no controller ever queries the whole table" discipline every other module in this
 * project applies to a resource with an owner.
 */
class NotificationService
{
    /**
     * @return LengthAwarePaginator<array-key, DatabaseNotification>
     */
    public function paginate(User $user, int $perPage): LengthAwarePaginator
    {
        return $user->notifications()->paginate($perPage);
    }

    /**
     * @return LengthAwarePaginator<array-key, DatabaseNotification>
     */
    public function paginateUnread(User $user, int $perPage): LengthAwarePaginator
    {
        return $user->unreadNotifications()->paginate($perPage);
    }

    /**
     * Idempotent: DatabaseNotification::markAsRead() already only writes when read_at is
     * still null, so a repeated call is a safe no-op, never a duplicate write or an error -
     * exactly what the brief's own requirement for this action asks for.
     */
    public function markAsRead(User $user, DatabaseNotification $notification): DatabaseNotification
    {
        $this->assertOwnedBy($user, $notification);

        $notification->markAsRead();

        return $notification;
    }

    /**
     * A single UPDATE over every currently-unread row, rather than
     * DatabaseNotificationCollection::markAsRead()'s own one-query-per-row loop - the identical
     * "avoid N+1" discipline this project's own list services already apply to eager loading,
     * applied here to a bulk write instead of a bulk read.
     */
    public function markAllAsRead(User $user): int
    {
        return $user->unreadNotifications()->update(['read_at' => now()]);
    }

    /**
     * A notification belongs to whoever it was sent to - never trusted merely because a
     * caller is authenticated at all, the identical IDOR discipline every other module in
     * this project applies to a record with an owner (ReportCardService's own enrollment
     * ownership check, ScoreService's teacher scope, and so on).
     */
    protected function assertOwnedBy(User $user, DatabaseNotification $notification): void
    {
        if ($notification->notifiable_type !== $user->getMorphClass() || $notification->notifiable_id !== $user->id) {
            throw new AuthorizationException('This notification does not belong to you.');
        }
    }
}
