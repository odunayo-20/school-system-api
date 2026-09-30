<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * A login account has just been provisioned for this user (Module 03's staff creation is the
 * one place in this project that creates a brand-new User together with its credentials in a
 * single step - see StaffService::create()). Sits in the account's own notification list from
 * the moment it is created, visible the first time they log in.
 *
 * Only wired to staff creation today. Module 04's pupil creation deliberately creates no
 * account at all (see the students migration), so there is no equivalent moment for a student
 * yet - adding one is a future portal module's decision, not fabricated here.
 *
 * Database only - see ResultPublishedNotification's own docblock for why no other channel is
 * wired up yet, and why this is not queued.
 */
class AccountCreatedNotification extends Notification
{
    public function __construct(protected User $user) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'user_id' => $this->user->id,
            'name' => $this->user->name,
            'email' => $this->user->email,
            'message' => "Welcome, {$this->user->name}. Your account has been created.",
        ];
    }
}
