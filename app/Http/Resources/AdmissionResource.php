<?php

namespace App\Http\Resources;

use App\Http\Resources\Academic\AcademicSessionResource;
use App\Http\Resources\Academic\ClassLevelResource;
use App\Models\Admission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One admission record: the applicant's snapshot, the intake it targets, and the decision.
 *
 * Every field is listed explicitly, matching StudentResource's convention. There is
 * deliberately no `class_id`, `section_id` or anything that would read as an authoritative
 * placement - `entry_class_level` is what the applicant is seeking, not where they have been
 * put.
 *
 * `student` is null until the admission is ADMITTED, and is rendered through StudentResource
 * rather than the raw model, so the same privacy rules Module 04 already settled on - no
 * email, no account internals - apply here without being re-decided.
 *
 * @mixin Admission
 */
class AdmissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'admission_number' => $this->admission_number,

            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'full_name' => $this->fullName(),

            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'gender' => $this->gender?->value,

            'status' => $this->status->value,
            'notes' => $this->notes,
            'decided_at' => $this->decided_at?->toIso8601String(),

            'academic_session' => $this->whenLoaded(
                'academicSession',
                fn () => $this->academicSession ? new AcademicSessionResource($this->academicSession) : null,
            ),
            'entry_class_level' => $this->whenLoaded(
                'entryClassLevel',
                fn () => $this->entryClassLevel ? new ClassLevelResource($this->entryClassLevel) : null,
            ),

            // Null until ADMITTED. The pupil this admission created, once it exists -
            // never the other way around, and never a second path to create one.
            'student' => $this->whenLoaded(
                'student',
                fn () => $this->student ? new StudentResource($this->student) : null,
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
