<?php

namespace App\Http\Resources;

use App\Http\Resources\Academic\ClassLevelResource;
use App\Models\GradingScale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One grading scale: its identity, the class level it applies to, its lifecycle, and its
 * percentage bands.
 *
 * items is included only `whenLoaded`, so the list endpoint (which does not eager-load them -
 * a scale's identity and scope are enough to browse a list by) stays a small, flat row, while
 * show() and the write endpoints - which DO load items, since seeing or having just changed a
 * scale's bands is the whole point of those calls - render the full configuration. This
 * mirrors SubjectResource's own use of `class_subjects_count` only where it was counted rather
 * than exposing it unconditionally.
 *
 * @mixin GradingScale
 */
class GradingScaleResource extends JsonResource
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

            'class_level' => $this->whenLoaded('classLevel', fn () => new ClassLevelResource($this->classLevel)),

            'items' => $this->whenLoaded('items', fn () => GradingScaleItemResource::collection($this->items)),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
