<?php

namespace App\Notifications;

use App\Models\Result;
use Illuminate\Notifications\Notification;

/**
 * A student's result for one class subject and term has been published (Module 13) and is
 * now theirs to see, through their own report card or the result checker.
 *
 * DATABASE ONLY, DELIBERATELY. This project has no outbound mail/SMS provider configured
 * beyond the framework's own `log` mailer default (see config/mail.php), so a `mail` channel
 * here would silently write to a log file rather than reach anyone - worse than not offering
 * it. `via()` returning a single-element array is exactly where a future `mail`/`sms` channel
 * is added, once this project actually configures one; nothing else about this class would
 * change. Not queued: writing one row to the notifications table is cheap and synchronous
 * elsewhere in this project's own write paths, and a queued job that could silently fail
 * would need failure-handling this module has no requirement to build yet.
 *
 * Fired once, from ResultService::publish() only - never from submit()/approve()/lock() -
 * because "published" is the one transition this project's own docs already name as the
 * hand-off point a student's own access begins at (see ResultStatus's own docblock).
 */
class ResultPublishedNotification extends Notification
{
    public function __construct(protected Result $result) {}

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
        $this->result->loadMissing(['classSubject.subject', 'term.academicSession']);

        return [
            'result_id' => $this->result->id,
            'subject' => $this->result->classSubject->subject->name,
            'term' => $this->result->term->name,
            'academic_session' => $this->result->term->academicSession->name,
            'percentage' => (string) $this->result->percentage,
            'grade' => $this->result->grade,
            'message' => "Your result for {$this->result->classSubject->subject->name} ({$this->result->term->name}) has been published.",
        ];
    }
}
