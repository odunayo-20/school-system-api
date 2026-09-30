<?php

namespace App\Http\Requests\Notification;

use App\Http\Requests\ListRequest;

/**
 * Paging only, for both GET /notifications and GET /notifications/unread. The list is always
 * the caller's own - there is nothing else to filter it by, and no `search`: a notification's
 * `data` payload is structured, not free text, matching every other list in this API that has
 * no text field of its own.
 */
class NotificationListRequest extends ListRequest
{
    /**
     * @return array<string, mixed>
     */
    protected function filterRules(): array
    {
        return [
            'search' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'search.prohibited' => 'Notifications cannot be searched by text.',
        ]);
    }
}
