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
     * A paginated list of resources, with the page metadata kept OUT of the data key.
     *
     * Laravel's default paginated resource nests the items one level deeper than every
     * other response in this API:
     *
     *     { "data": { "data": [...], "links": {...}, "meta": {...} } }
     *
     * so a client that has learned to read "data" as the payload has to special case
     * every list endpoint. Here "data" is always the list and the links and meta are its
     * siblings, so one code path reads every endpoint in the API.
     *
     * The shape is built here rather than taken from ResourceCollection::resolve(), which
     * returns a bare list of resolved items and leaves the paginator to
     * PaginatedResourceResponse to describe. The collection is still resolved through
     * Eloquent, so each item is rendered by its own API Resource with its whitelisted
     * fields rather than the model being serialised directly.
     *
     * @param  ResourceCollection<array-key, mixed>  $collection  e.g. JsonResource::collection($paginator)
     */
    public static function paginated(ResourceCollection $collection, ?string $message = null): JsonResponse
    {
        $payload = ['data' => $collection->resolve(request())];

        // A ResourceCollection wraps either a paginator or a plain collection. Only the
        // paginator has pages to describe, so the meta keys are added only when they are
        // meaningful rather than reporting a single page of invented metadata.
        $paginator = $collection->resource;

        if ($paginator instanceof LengthAwarePaginator) {
            $payload['meta'] = [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ];

            $payload['links'] = [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ];
        }

        if (! is_null($message)) {
            $payload['message'] = $message;
        }

        return response()->json($payload);
    }

    /**
     * A success response whose payload is deliberately null, sent as "data": null.
     *
     * success() omits the data key entirely when handed null, which is right for an
     * operation that simply succeeded and has nothing to return: a delete, a logout. It is
     * the wrong shape where the absence of a resource IS the answer. A client reading
     * body.data then finds the key missing rather than present and null, and those are not
     * the same thing to handle: one is "this endpoint did not include a payload", the other
     * is "I asked, and there is nothing there".
     */
    public static function nullData(string $message): JsonResponse
    {
        return response()->json([
            'data' => null,
            'message' => $message,
        ]);
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
