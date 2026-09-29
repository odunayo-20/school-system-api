<?php

namespace App\Http\Resources;

use App\Http\Resources\Academic\SchoolClassResource;
use App\Models\ClassSubject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One class-subject offering: which class, which subject, and whether it is currently
 * offered. Both related records are rendered through their own module's resource -
 * SchoolClassResource, SubjectResource - so this module maintains no second field list for
 * either.
 *
 * @mixin ClassSubject
 */
class ClassSubjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'school_class' => $this->whenLoaded('schoolClass', fn () => new SchoolClassResource($this->schoolClass)),
            'subject' => $this->whenLoaded('subject', fn () => new SubjectResource($this->subject)),
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
