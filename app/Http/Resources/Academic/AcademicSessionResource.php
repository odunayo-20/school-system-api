<?php

namespace App\Http\Resources\Academic;

use App\Models\AcademicSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AcademicSession
 */
class AcademicSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'status' => $this->status->value,
            // active_marker is derived from status by the model and is never exposed: a
            // client should ask "what is the status" and "which is current", never read an
            // internal uniqueness token and try to interpret it.
            'is_current' => $this->isCurrent(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
