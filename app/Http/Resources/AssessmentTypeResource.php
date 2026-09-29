<?php

namespace App\Http\Resources;

use App\Models\AssessmentType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One assessment category. Concise on purpose - matching SubjectResource's own field list
 * exactly, since the two models share a shape.
 *
 * @mixin AssessmentType
 */
class AssessmentTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'sort_order' => $this->sort_order,
            'status' => $this->status->value,
            'assessments_count' => $this->whenCounted('assessments'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
