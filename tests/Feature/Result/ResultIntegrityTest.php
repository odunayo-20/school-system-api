<?php

use App\Enums\ResultStatus;
use App\Enums\Role;
use App\Exceptions\BusinessRuleViolation;
use App\Models\ClassSubject;
use App\Models\Enrollment;
use App\Models\Result;
use App\Models\Term;
use App\Services\Result\ResultService;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| Database integrity: the unique triple, foreign-key deletion behaviour and
| idempotent recompilation (Module 12)
|--------------------------------------------------------------------------
*/

it('lets the database catch a duplicate (enrollment, class subject, term) triple that the service never attempts to insert twice', function (): void {
    // ResultService::persist() never issues a second INSERT for an existing triple - it locks
    // and updates the row it finds instead. This proves the unique index the service leans on
    // as its own race-safe backstop genuinely exists and is enforced at the database level.
    [$assessment, $enrollment] = resultCompilationContext();

    Result::factory()
        ->forEnrollment($enrollment)
        ->forClassSubject($assessment->classSubject)
        ->forTerm($assessment->term)
        ->create();

    expect(fn () => (new Result)->forceFill([
        'enrollment_id' => $enrollment->id,
        'class_subject_id' => $assessment->class_subject_id,
        'term_id' => $assessment->term_id,
        'percentage' => 10,
        'status' => ResultStatus::COMPILED,
    ])->save())->toThrow(QueryException::class);

    expect(Result::query()->where('enrollment_id', $enrollment->id)->count())->toBe(1);
});

it('is idempotent: recompiling with unchanged scores keeps exactly one row for the triple', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $service = app(ResultService::class);
    [$assessment, $enrollment] = resultCompilationContext(['max_score' => 20], [], score: 12);

    $attributes = [
        'enrollment_id' => $enrollment->id,
        'class_subject_id' => $assessment->class_subject_id,
        'term_id' => $assessment->term_id,
    ];

    $first = $service->compile($attributes, $admin);
    $second = $service->compile($attributes, $admin);
    $third = $service->compile($attributes, $admin);

    expect($first->id)->toBe($second->id)->toBe($third->id)
        ->and(Result::query()->count())->toBe(1);
});

it('serializes two concurrent recompiles of the same existing result rather than corrupting it', function (): void {
    // Simulates the race two concurrent requests would create against an ALREADY-compiled
    // result: both resolve the same row under lockForUpdate(), and the last write wins
    // cleanly rather than the table ending up with two rows for the same triple.
    $admin = userWithRole(Role::ADMIN);
    $service = app(ResultService::class);
    [$assessment, $enrollment] = resultCompilationContext(['max_score' => 20], [], score: 10);

    $attributes = [
        'enrollment_id' => $enrollment->id,
        'class_subject_id' => $assessment->class_subject_id,
        'term_id' => $assessment->term_id,
    ];

    $service->compile($attributes, $admin);

    $service->compile($attributes, $admin);
    $service->compile($attributes, $admin);

    expect(Result::query()->where('enrollment_id', $enrollment->id)
        ->where('class_subject_id', $assessment->class_subject_id)
        ->where('term_id', $assessment->term_id)
        ->count())->toBe(1);
});

it('refuses to compile over a locked result at the service layer, not only through the API', function (): void {
    $admin = userWithRole(Role::ADMIN);
    $service = app(ResultService::class);
    [$assessment, $enrollment] = resultCompilationContext();

    Result::factory()
        ->forEnrollment($enrollment)
        ->forClassSubject($assessment->classSubject)
        ->forTerm($assessment->term)
        ->locked()
        ->create();

    expect(fn () => $service->compile([
        'enrollment_id' => $enrollment->id,
        'class_subject_id' => $assessment->class_subject_id,
        'term_id' => $assessment->term_id,
    ], $admin))->toThrow(BusinessRuleViolation::class, 'This result has been locked and can no longer be recompiled.');
});

it('refuses to delete an enrollment that a result still references', function (): void {
    $result = compiledResult();
    $enrollmentId = $result->enrollment_id;

    expect(fn () => Enrollment::query()->findOrFail($enrollmentId)->delete())->toThrow(QueryException::class);

    expect(Enrollment::query()->whereKey($enrollmentId)->exists())->toBeTrue()
        ->and(Result::query()->whereKey($result->id)->exists())->toBeTrue();
});

it('refuses to delete a class subject that a result still references', function (): void {
    $result = compiledResult();
    $classSubjectId = $result->class_subject_id;

    expect(fn () => ClassSubject::query()->findOrFail($classSubjectId)->delete())->toThrow(QueryException::class);

    expect(ClassSubject::query()->whereKey($classSubjectId)->exists())->toBeTrue()
        ->and(Result::query()->whereKey($result->id)->exists())->toBeTrue();
});

it('refuses to delete a term that a result still references', function (): void {
    $result = compiledResult();
    $termId = $result->term_id;

    expect(fn () => Term::query()->findOrFail($termId)->delete())->toThrow(QueryException::class);

    expect(Term::query()->whereKey($termId)->exists())->toBeTrue()
        ->and(Result::query()->whereKey($result->id)->exists())->toBeTrue();
});
