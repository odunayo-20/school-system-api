<?php

namespace App\Notifications;

use App\Models\Enrollment;
use Illuminate\Notifications\Notification;

/**
 * A student has been placed in a class and section for an academic session (Module 06).
 *
 * Only ever sent when the enrolled student already holds a linked portal account
 * (`Student.user_id` is not null) - most pupils in this project have none (see the students
 * migration: a pupil's identity does not require a login), so EnrollmentService::create()
 * checks for one and simply does not dispatch this notification when there is nobody to
 * notify. That check lives in the service, not here; this class only shapes the payload once
 * a real recipient is already known.
 *
 * Database only - see ResultPublishedNotification's own docblock for why no other channel is
 * wired up yet, and why this is not queued.
 */
class EnrollmentNotification extends Notification
{
    public function __construct(protected Enrollment $enrollment) {}

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
        $this->enrollment->loadMissing(['academicSession', 'schoolClass', 'section']);

        return [
            'enrollment_id' => $this->enrollment->id,
            'academic_session' => $this->enrollment->academicSession->name,
            'school_class' => $this->enrollment->schoolClass->name,
            'section' => $this->enrollment->section->name,
            'message' => "You have been enrolled in {$this->enrollment->schoolClass->name} {$this->enrollment->section->name} for {$this->enrollment->academicSession->name}.",
        ];
    }
}
