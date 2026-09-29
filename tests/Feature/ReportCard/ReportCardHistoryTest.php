<?php

use App\Enums\ResultStatus;
use App\Enums\Role;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Report card history: GET /report-cards/students/{student} (Module 14)
|--------------------------------------------------------------------------
*/

it('lists every term with a finalized report card for a student, newest first', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $student = pupilWithAccount();

    [, $termOld] = reportCardContext(1, ResultStatus::PUBLISHED, ['student_id' => $student->id]);
    [, $termNew] = reportCardContext(1, ResultStatus::PUBLISHED, ['student_id' => $student->id]);

    $response = withToken($token)->getJson("/api/v1/report-cards/students/{$student->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $termIds = collect($response->json('data'))->pluck('term.id');
    expect($termIds->first())->toBe($termNew->id)
        ->and($termIds->last())->toBe($termOld->id);
});

it('excludes a term with only unpublished results from the history', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $student = pupilWithAccount();

    reportCardContext(1, ResultStatus::PUBLISHED, ['student_id' => $student->id]);
    reportCardContext(1, ResultStatus::SUBMITTED, ['student_id' => $student->id]);

    withToken($token)->getJson("/api/v1/report-cards/students/{$student->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('returns an empty list rather than an error for a student with no finalized report cards yet', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $student = pupilWithAccount();

    withToken($token)->getJson("/api/v1/report-cards/students/{$student->id}")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('returns an empty list rather than an error for a student with no enrollment at all', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $student = pupil();

    withToken($token)->getJson("/api/v1/report-cards/students/{$student->id}")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('carries the correct summary figures on each history row', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $student = pupilWithAccount();
    [, , $results] = reportCardContext(2, ResultStatus::PUBLISHED, ['student_id' => $student->id]);

    $expectedPercentage = number_format((float) $results->avg('percentage'), 2, '.', '');

    withToken($token)->getJson("/api/v1/report-cards/students/{$student->id}")
        ->assertOk()
        ->assertJsonPath('data.0.subjects_count', 2)
        ->assertJsonPath('data.0.overall_percentage', $expectedPercentage);
});

it('paginates the history list and caps the page size', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $student = pupilWithAccount();

    foreach (range(1, 3) as $i) {
        reportCardContext(1, ResultStatus::PUBLISHED, ['student_id' => $student->id]);
    }

    $response = withToken($token)->getJson("/api/v1/report-cards/students/{$student->id}?per_page=2")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect($response->json('meta.total'))->toBe(3)
        ->and($response->json('meta.last_page'))->toBe(2);
});

it('rejects invalid pagination', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $student = pupilWithAccount();

    withToken($token)->getJson("/api/v1/report-cards/students/{$student->id}?per_page=0")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
});

it('rejects a search parameter, because a history row has no text field to search', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $student = pupilWithAccount();

    withToken($token)->getJson("/api/v1/report-cards/students/{$student->id}?search=anything")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('search');
});

it('lists history through the same envelope as every other list in the API', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $student = pupilWithAccount();
    reportCardContext(1, ResultStatus::PUBLISHED, ['student_id' => $student->id]);

    withToken($token)->getJson("/api/v1/report-cards/students/{$student->id}")
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['enrollment', 'term', 'subjects_count', 'overall_percentage', 'average_grade_point']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);
});

it('does not generate N+1 queries as the number of history rows grows', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    $studentSmall = pupilWithAccount();
    reportCardContext(2, ResultStatus::PUBLISHED, ['student_id' => $studentSmall->id]);

    $studentLarge = pupilWithAccount();
    foreach (range(1, 5) as $i) {
        reportCardContext(2, ResultStatus::PUBLISHED, ['student_id' => $studentLarge->id]);
    }

    DB::enableQueryLog();
    withToken($token)->getJson("/api/v1/report-cards/students/{$studentSmall->id}")->assertOk();
    $smallCount = count(DB::getQueryLog());
    DB::flushQueryLog();

    withToken($token)->getJson("/api/v1/report-cards/students/{$studentLarge->id}")->assertOk();
    $largeCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    // The query count stays flat as history rows grow from 1 to 5 - the bulk enrollment and
    // term hydration are each a single whereIn() query regardless of how many rows are on the
    // page. A small, constant tolerance absorbs incidental, non-scaling overhead (e.g.
    // Sanctum's own token bookkeeping); a genuine per-row query would instead move
    // $largeCount by roughly the extra 4 rows, far past this margin.
    expect($largeCount)->toBeLessThanOrEqual($smallCount + 2);
});
