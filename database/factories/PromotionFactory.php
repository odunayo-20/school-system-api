<?php

namespace Database\Factories;

use App\Enums\PromotionDecision;
use App\Models\AcademicSession;
use App\Models\Enrollment;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Promotion>
 *
 * Factory-created models bypass $fillable entirely (Factory::make() wraps instantiation in
 * Model::unguarded()), which is what lets this definition set every column directly even
 * though none is mass-assignable through the API - the identical situation ResultFactory and
 * every other anchor-style factory in this project is already in for their own guarded
 * reference and decision fields.
 */
class PromotionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_enrollment_id' => Enrollment::factory(),
            'target_academic_session_id' => AcademicSession::factory(),
            'target_enrollment_id' => Enrollment::factory(),
            'decision' => PromotionDecision::PROMOTED,
            'reason' => null,
            'decided_by' => User::factory()->admin(),
            'decided_at' => now(),
        ];
    }

    public function forSourceEnrollment(Enrollment $enrollment): static
    {
        return $this->state(fn (array $attributes): array => [
            'source_enrollment_id' => $enrollment->getKey(),
        ]);
    }

    public function forTargetSession(AcademicSession $session): static
    {
        return $this->state(fn (array $attributes): array => [
            'target_academic_session_id' => $session->getKey(),
        ]);
    }

    public function forTargetEnrollment(?Enrollment $enrollment): static
    {
        return $this->state(fn (array $attributes): array => [
            'target_enrollment_id' => $enrollment?->getKey(),
        ]);
    }

    public function decidedBy(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'decided_by' => $user->getKey(),
        ]);
    }

    public function retained(): static
    {
        return $this->state(fn (array $attributes): array => [
            'decision' => PromotionDecision::RETAINED,
        ]);
    }

    public function graduated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'decision' => PromotionDecision::GRADUATED,
            'target_enrollment_id' => null,
        ]);
    }

    public function notEligible(): static
    {
        return $this->state(fn (array $attributes): array => [
            'decision' => PromotionDecision::NOT_ELIGIBLE,
            'target_enrollment_id' => null,
        ]);
    }
}
