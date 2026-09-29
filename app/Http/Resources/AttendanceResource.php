<?php

namespace App\Http\Resources;

use App\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One attendance mark: the enrollment it was taken against, the date, the status, and who
 * recorded it.
 *
 * academic_session_id/school_class_id/section_id are NOT surfaced as separate top-level keys
 * here even though the table stores them - the enrollment already nests the session, class and
 * section through EnrollmentResource, and repeating them a second time flattened at this
 * level would be exactly the second, divergent field list every resource in this project
 * avoids for a nested record. The stored copies exist purely for this module's own indexing
 * (see the attendances migration); a client reads them through `enrollment`, the single
 * source of truth for what they mean.
 *
 * `recorded_by` is rendered as the minimal {id, name} projection every other "who acted" field
 * in this API already uses (ResultResource's submitted_by/approved_by, PromotionResource's
 * decided_by), never a full UserResource, and is null once the recording account has been
 * deleted - see the attendances migration for why recorded_by is nullOnDelete.
 *
 * @mixin Attendance
 */
class AttendanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'enrollment' => $this->whenLoaded('enrollment', fn () => new EnrollmentResource($this->enrollment)),

            'date' => $this->date?->toDateString(),
            'status' => $this->status->value,
            'remarks' => $this->remarks,

            'recorded_by' => $this->whenLoaded('recordedBy', fn (): ?array => $this->recordedBy
                ? ['id' => $this->recordedBy->id, 'name' => $this->recordedBy->name]
                : null),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
