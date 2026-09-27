<?php

namespace App\Support;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The single response shape for the whole API.
 *
 * Success: { "data": ..., "message": "..." }
 * Failure: { "message": "...", "errors"?: { field: [messages] } }
 *
 * HTTP status codes always carry the meaning: 401 unauthenticated, 403 forbidden,
 * 422 validation failed, 429 rate limited. A failure is never returned with 200.
 */
final class ApiResponse
{
    /**
     * @param  Arrayable|JsonResource|ResourceCollection|LengthAwarePaginator|array<array-key, mixed>|scalar|null  $data
     */
    public static function success(
        Arrayable|JsonResource|ResourceCollection|LengthAwarePaginator|array|scalar|null $data = null,
        ?string $message = null,
        int $status = 200,
    ): JsonResponse {
        $payload = is_null($data) ? [] : ['data' => $data];

        if (! is_null($message)) {
            $payload['message'] = $message;
        }

        return response()->json($payload, $status);
    }

    /**
     * @param  array<string, list<string>|string>  $errors
     */
    public static function error(string $message, int $status, array $errors = []): JsonResponse
    {
        $payload = ['message' => $message];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }

    public static function unauthenticated(string $message = 'Unauthenticated.'): JsonResponse
    {
        return self::error($message, 401);
    }

    public static function forbidden(string $message = 'This action is unauthorized.'): JsonResponse
    {
        return self::error($message, 403);
    }

    public static function tooManyRequests(string $message = 'Too many requests.'): JsonResponse
    {
        return self::error($message, 429);
    }
}
