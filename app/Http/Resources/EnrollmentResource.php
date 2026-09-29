<?php

namespace App\Http\Resources;

use App\Http\Resources\Academic\AcademicSessionResource;
use App\Http\Resources\Academic\SchoolClassResource;
use App\Http\Resources\Academic\SectionResource;
use App\Models\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One enrollment: the student, the session, the class and section, and the placement's own
 * lifecycle.
 *
 * Every field is listed explicitly, matching every other resource in this project. The
 * related records are rendered through their own module's resource - StudentResource,
 * AcademicSessionResource, SchoolClassResource, SectionResource - so this module does not
 * maintain a second, divergent field list for any of them, and StudentResource's own privacy
 * rules (no email, no account internals) apply here without being re-decided.
 *
 * @mixin Enrollment
 */
class EnrollmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'student' => $this->whenLoaded('student', fn () => new StudentResource($this->student)),
            'academic_session' => $this->whenLoaded('academicSession', fn () => new AcademicSessionResource($this->academicSession)),
            'school_class' => $this->whenLoaded('schoolClass', fn () => new SchoolClassResource($this->schoolClass)),
            'section' => $this->whenLoaded('section', fn () => new SectionResource($this->section)),

            'enrollment_date' => $this->enrollment_date?->toDateString(),
            'status' => $this->status->value,
            'notes' => $this->notes,
            'status_changed_at' => $this->status_changed_at?->toIso8601String(),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
