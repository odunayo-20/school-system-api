<?php

namespace App\Http\Resources\Academic;

use App\Models\SchoolClass;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SchoolClass
 */
class SchoolClassResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'class_level_id' => $this->class_level_id,
            'class_level' => $this->whenLoaded(
                'classLevel',
                fn (): ?array => $this->classLevel ? [
                    'id' => $this->classLevel->getKey(),
                    'name' => $this->classLevel->name,
                    'code' => $this->classLevel->code,
                ] : null,
            ),
            'name' => $this->name,
            'code' => $this->code,
            'sort_order' => $this->sort_order,
            'status' => $this->status->value,
            'sections_count' => $this->whenCounted('sections'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
