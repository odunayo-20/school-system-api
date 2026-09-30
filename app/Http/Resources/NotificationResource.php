<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * One database notification: what kind it is, its payload, and whether it has been read.
 *
 * `type` is rendered as the notification class's own short name (e.g.
 * "ResultPublishedNotification") rather than the full class-qualified string Laravel stores -
 * a client should never have to know or parse this project's own namespace layout to branch on
 * notification type.
 *
 * `data` is exactly what the notification class's own toArray() produced - see
 * ResultPublishedNotification/EnrollmentNotification/AccountCreatedNotification for the shape
 * each one sends. No internal database columns (notifiable_type, notifiable_id) are exposed:
 * the caller already knows these are their own, and neither tells them anything they need.
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => class_basename($this->type),
            'data' => $this->data,
            'read' => $this->read_at !== null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
