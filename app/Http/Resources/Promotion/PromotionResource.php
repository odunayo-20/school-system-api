<?php

namespace App\Http\Resources\Promotion;

use App\Http\Resources\Academic\AcademicSessionResource;
use App\Http\Resources\EnrollmentResource;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One promotion decision: the source enrollment, the target academic session, the target
 * enrollment where one was created, the decision itself, and who decided it.
 *
 * `source_enrollment`/`target_enrollment` both reuse EnrollmentResource wholesale (already
 * nesting student, academic session, class and section) rather than flattening those pieces
 * here a second time - the identical "no second field list" discipline every resource in this
 * project already applies to its own nested records.
 *
 * `decided_by` is a small {id, name} actor projection, never the full UserResource - matching
 * ResultResource's own identical choice for submitted_by/approved_by/etc., so viewing a
 * promotion never leaks the deciding administrator's email or permission list.
 *
 * @mixin Promotion
 */
class PromotionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'source_enrollment' => $this->whenLoaded('sourceEnrollment', fn () => new EnrollmentResource($this->sourceEnrollment)),
            'target_academic_session' => $this->whenLoaded('targetAcademicSession', fn () => new AcademicSessionResource($this->targetAcademicSession)),
            'target_enrollment' => $this->whenLoaded('targetEnrollment', fn () => $this->targetEnrollment ? new EnrollmentResource($this->targetEnrollment) : null),

            'decision' => $this->decision->value,
            'reason' => $this->reason,

            'decided_by' => $this->whenLoaded('decidedBy', fn () => $this->actor($this->decidedBy)),
            'decided_at' => $this->decided_at?->toIso8601String(),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    protected function actor(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => $user->name] : null;
    }
}
