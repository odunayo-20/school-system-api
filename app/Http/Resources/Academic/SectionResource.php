<?php

namespace App\Http\Resources\Academic;

use App\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Section
 */
class SectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'school_class_id' => $this->school_class_id,
            'school_class' => $this->whenLoaded(
                'schoolClass',
                fn (): ?array => $this->schoolClass ? [
                    'id' => $this->schoolClass->getKey(),
                    'name' => $this->schoolClass->name,
                    'code' => $this->schoolClass->code,
                ] : null,
            ),
            'name' => $this->name,
            'code' => $this->code,
            'sort_order' => $this->sort_order,
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
