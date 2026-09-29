<?php

namespace App\Http\Resources;

use App\Http\Resources\Academic\AcademicSessionResource;
use App\Models\TeacherAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One teaching assignment: the teacher, the class subject, the session, and the assignment's
 * own lifecycle.
 *
 * Every field is listed explicitly, matching every other resource in this project. Related
 * records are rendered through their own module's resource - StaffResource, ClassSubjectResource
 * (which itself nests SchoolClassResource and SubjectResource), AcademicSessionResource - so
 * this module maintains no second, divergent field list for any of them, and StaffResource's
 * own privacy rules (no password, no token, no account internals beyond name/email/status)
 * apply here without being re-decided.
 *
 * @mixin TeacherAssignment
 */
class TeacherAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'teaching_staff' => $this->whenLoaded('teachingStaff', fn () => new StaffResource($this->teachingStaff)),
            'class_subject' => $this->whenLoaded('classSubject', fn () => new ClassSubjectResource($this->classSubject)),
            'academic_session' => $this->whenLoaded('academicSession', fn () => new AcademicSessionResource($this->academicSession)),

            'status' => $this->status->value,
            'notes' => $this->notes,
            'ended_at' => $this->ended_at?->toIso8601String(),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
