<?php

namespace App\Http\Resources\Academic;

use App\Models\Term;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Term
 */
class TermResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'academic_session_id' => $this->academic_session_id,
            // The session is eager loaded by the service on the flat endpoints, so this
            // costs no extra query. whereLoaded() keeps the resource safe to use anywhere
            // the relation has not been fetched, instead of triggering a lazy load per row
            // in a list.
            'academic_session' => $this->whenLoaded(
                'academicSession',
                fn (): ?array => $this->academicSession ? [
                    'id' => $this->academicSession->getKey(),
                    'name' => $this->academicSession->name,
                    'status' => $this->academicSession->status->value,
                ] : null,
            ),
            'name' => $this->name,
            'term_number' => $this->term_number,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'status' => $this->status->value,
            'is_current' => $this->isCurrent(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
