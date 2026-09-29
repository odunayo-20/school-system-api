<?php

namespace Database\Factories;

use App\Enums\ResultStatus;
use App\Models\ClassSubject;
use App\Models\Enrollment;
use App\Models\Result;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Result>
 *
 * Factory-created models bypass $fillable entirely (Factory::make() wraps instantiation in
 * Model::unguarded()), which is what lets this definition set enrollment_id/class_subject_id/
 * term_id directly even though none is mass-assignable through the API - the identical
 * situation ScoreFactory and every other anchor-style factory in this project is already in
 * for their own guarded reference fields.
 */
class ResultFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'class_subject_id' => ClassSubject::factory(),
            'term_id' => Term::factory(),
            'percentage' => fake()->randomFloat(2, 0, 100),
            'grade' => null,
            'grade_point' => null,
            'remark' => null,
            'status' => ResultStatus::COMPILED,
        ];
    }

    public function forEnrollment(Enrollment $enrollment): static
    {
        return $this->state(fn (array $attributes): array => [
            'enrollment_id' => $enrollment->getKey(),
        ]);
    }

    public function forClassSubject(ClassSubject $classSubject): static
    {
        return $this->state(fn (array $attributes): array => [
            'class_subject_id' => $classSubject->getKey(),
        ]);
    }

    public function forTerm(Term $term): static
    {
        return $this->state(fn (array $attributes): array => [
            'term_id' => $term->getKey(),
        ]);
    }

    public function graded(string $grade, ?float $gradePoint = null, ?string $remark = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'grade' => $grade,
            'grade_point' => $gradePoint,
            'remark' => $remark,
            'status' => ResultStatus::COMPILED,
        ]);
    }

    public function incomplete(): static
    {
        return $this->state(fn (array $attributes): array => [
            'grade' => null,
            'grade_point' => null,
            'remark' => null,
            'status' => ResultStatus::INCOMPLETE,
        ]);
    }

    public function locked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ResultStatus::LOCKED,
        ]);
    }
}
