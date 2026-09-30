<?php

namespace App\Http\Controllers\Api\V1\Notification;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\NotificationListRequest;
use App\Http\Resources\NotificationResource;
use App\Services\Notification\NotificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Reading and acknowledging the authenticated user's own notifications.
 *
 * Every route is gated on `auth:api`/`active` only - no `permission:` middleware - the
 * identical shape `GET /auth/me` already uses for "my own data, nothing to authorize beyond
 * being this account." There is nothing here for a role-based permission to usefully gate: a
 * notification is intrinsically scoped to the one account it was sent to, never to a resource
 * a role could be granted broader or narrower access over.
 *
 * No destroy(): dismissing a notification in this project means marking it read, not deleting
 * it - the same "correct forward, never erase" posture every historical record in this project
 * already takes.
 */
class NotificationController extends Controller
{
    public function __construct(protected NotificationService $notifications) {}

    public function index(NotificationListRequest $request): JsonResponse
    {
        return ApiResponse::paginated(
            NotificationResource::collection(
                $this->notifications->paginate($request->user(), $request->perPage())
            )
        );
    }

    public function unread(NotificationListRequest $request): JsonResponse
    {
        return ApiResponse::paginated(
            NotificationResource::collection(
                $this->notifications->paginateUnread($request->user(), $request->perPage())
            )
        );
    }

    public function markAsRead(Request $request, DatabaseNotification $notification): JsonResponse
    {
        $read = $this->notifications->markAsRead($request->user(), $notification);

        return ApiResponse::success(new NotificationResource($read), 'Notification marked as read.');
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $this->notifications->markAllAsRead($request->user());

        return ApiResponse::success(null, 'All notifications marked as read.');
    }
}
