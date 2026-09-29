<?php

use App\Enums\Role;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\Score;
use App\Services\Score\ScoreService;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| Database integrity: races and foreign-key deletion behaviour (Module 10)
|--------------------------------------------------------------------------
*/

it('lets the database catch a duplicate score that validation could not, and reports it as a 422', function (): void {
    // Simulates the race two concurrent requests would create: both pass validation (neither
    // sees the other's row yet), and the second insert is the one that must fail cleanly -
    // the identical technique every prior module's own integrity test uses for its own unique
    // index.
    $service = app(ScoreService::class);
    [$assessment, $enrollment] = matchedScoreContext();
    $admin = userWithRole(Role::ADMIN);

    $service->create(['assessment_id' => $assessment->id, 'enrollment_id' => $enrollment->id, 'score' => 10], $admin);

    expect(fn () => $service->create([
        'assessment_id' => $assessment->id,
        'enrollment_id' => $enrollment->id,
        'score' => 12,
    ], $admin))->toThrow(BusinessRuleViolation::class, 'This enrollment already has a score for this assessment.');

    expect(Score::query()->where('assessment_id', $assessment->id)->count())->toBe(1);
});

it('refuses to delete an assessment that a score still references', function (): void {
    $score = recordedScore();
    $assessmentId = $score->assessment_id;

    expect(fn () => $score->assessment->delete())->toThrow(QueryException::class);

    expect(Assessment::query()->whereKey($assessmentId)->exists())->toBeTrue()
        ->and(Score::query()->whereKey($score->id)->exists())->toBeTrue();
});

it('refuses to delete an enrollment that a score still references', function (): void {
    $score = recordedScore();
    $enrollmentId = $score->enrollment_id;

    expect(fn () => $score->enrollment->delete())->toThrow(QueryException::class);

    expect(Enrollment::query()->whereKey($enrollmentId)->exists())->toBeTrue()
        ->and(Score::query()->whereKey($score->id)->exists())->toBeTrue();
});
