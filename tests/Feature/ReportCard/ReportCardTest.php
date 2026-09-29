<?php

use App\Enums\CatalogStatus;
use App\Enums\ResultStatus;
use App\Enums\Role;
use App\Models\GradingScale;
use App\Models\Score;
use App\Models\Section;
use App\Models\Term;
use App\Services\Result\ResultService;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Report card retrieval, workflow visibility, data accuracy and historical
| safety (Module 14)
|--------------------------------------------------------------------------
*/

/*
| Retrieval
*/

it('returns a report card for a published result', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$enrollment, $term] = reportCardContext(2, ResultStatus::PUBLISHED);

    $response = withToken($token)->getJson("/api/v1/report-cards/enrollments/{$enrollment->id}/terms/{$term->id}");

    $response->assertOk()
        ->assertJsonPath('data.enrollment.id', $enrollment->id)
        ->assertJsonPath('data.term.id', $term->id)
        ->assertJsonCount(2, 'data.subjects');
});

it('returns a report card for a locked result', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$enrollment, $term] = reportCardContext(2, ResultStatus::LOCKED);

    withToken($token)->getJson("/api/v1/report-cards/enrollments/{$enrollment->id}/terms/{$term->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data.subjects')
        ->assertJsonPath('data.subjects.0.status', ResultStatus::LOCKED->value);
});

it('answers 404 for an enrollment id that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $term = Term::factory()->create();

    withToken($token)->getJson("/api/v1/report-cards/enrollments/999999/terms/{$term->id}")->assertNotFound();
});

it('answers 404 for a term id that does not exist', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$enrollment] = reportCardContext(1, ResultStatus::PUBLISHED);

    withToken($token)->getJson("/api/v1/report-cards/enrollments/{$enrollment->id}/terms/999999")->assertNotFound();
});

it('answers 404 for a student that does not exist on the history endpoint', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    withToken($token)->getJson('/api/v1/report-cards/students/999999')->assertNotFound();
});

it('answers 404 for an enrollment and term that do not belong to each other', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$enrollmentA] = reportCardContext(1, ResultStatus::PUBLISHED);
    [, $termB] = reportCardContext(1, ResultStatus::PUBLISHED);

    withToken($token)->getJson("/api/v1/report-cards/enrollments/{$enrollmentA->id}/terms/{$termB->id}")
        ->assertNotFound();
});

/*
| Workflow visibility
*/

it('does not expose an INCOMPLETE result as a finalized report card', function (): void {
    // reportCardContext() always scores its one assessment before compiling, so an
    // INCOMPLETE result (a configured assessment with no score at all) is built directly
    // here instead - the one status reportCardContext() cannot itself reach.
    $token = loginAs(userWithRole(Role::ADMIN));
    [$assessment, $enrollment] = matchedScoreContext(['max_score' => 20]);

    $result = app(ResultService::class)->compile([
        'enrollment_id' => $enrollment->id,
        'class_subject_id' => $assessment->class_subject_id,
        'term_id' => $assessment->term_id,
    ], userWithRole(Role::ADMIN));

    expect($result->status)->toBe(ResultStatus::INCOMPLETE);

    withToken($token)->getJson("/api/v1/report-cards/enrollments/{$enrollment->id}/terms/{$assessment->term_id}")
        ->assertNotFound();
});

it('does not expose a COMPILED-but-unsubmitted result as a finalized report card', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$enrollment, $term] = reportCardContext(1, ResultStatus::COMPILED);

    withToken($token)->getJson("/api/v1/report-cards/enrollments/{$enrollment->id}/terms/{$term->id}")
        ->assertNotFound();
});

it('does not expose a SUBMITTED result', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$enrollment, $term] = reportCardContext(1, ResultStatus::SUBMITTED);

    withToken($token)->getJson("/api/v1/report-cards/enrollments/{$enrollment->id}/terms/{$term->id}")
        ->assertNotFound();
});

it('does not expose an APPROVED-but-unpublished result', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$enrollment, $term] = reportCardContext(1, ResultStatus::APPROVED);

    withToken($token)->getJson("/api/v1/report-cards/enrollments/{$enrollment->id}/terms/{$term->id}")
        ->assertNotFound();
});

it('includes only the finalized subjects when a term mixes published and unpublished results', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$enrollment, $term, $results] = reportCardContext(2, ResultStatus::PUBLISHED);

    // A third subject in the SAME enrollment/term, deliberately left COMPILED.
    $classSubject = activeClassSubject(['school_class_id' => $enrollment->school_class_id]);
    $assessment = activeAssessment(['class_subject_id' => $classSubject->id, 'term_id' => $term->id, 'max_score' => 20]);
    Score::factory()->forAssessment($assessment)->forEnrollment($enrollment)->create(['score' => 14]);
    app(ResultService::class)->compile([
        'enrollment_id' => $enrollment->id,
        'class_subject_id' => $classSubject->id,
        'term_id' => $term->id,
    ], userWithRole(Role::ADMIN));

    $response = withToken($token)->getJson("/api/v1/report-cards/enrollments/{$enrollment->id}/terms/{$term->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data.subjects');

    $resultIds = collect($response->json('data.subjects'))->pluck('result_id')->all();
    expect($resultIds)->toEqualCanonicalizing($results->pluck('id')->all());
});

/*
| Data accuracy
*/

it('matches subject percentages, grades and remarks exactly to the authoritative Result rows', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$enrollment, $term, $results] = reportCardContext(2, ResultStatus::PUBLISHED);

    $response = withToken($token)->getJson("/api/v1/report-cards/enrollments/{$enrollment->id}/terms/{$term->id}")->assertOk();

    $bySubjectResultId = collect($response->json('data.subjects'))->keyBy('result_id');

    foreach ($results as $result) {
        $row = $bySubjectResultId->get($result->id);

        expect($row)->not->toBeNull()
            ->and($row['percentage'])->toBe((string) $result->percentage)
            ->and($row['grade'])->toBe($result->grade)
            ->and($row['grade_point'])->toBe($result->grade_point === null ? null : (string) $result->grade_point)
            ->and($row['remark'])->toBe($result->remark)
            ->and($row['status'])->toBe($result->status->value)
            ->and($row['class_subject']['id'])->toBe($result->class_subject_id);
    }
});

it('never recalculates a percentage - it is read verbatim from the Result row, not re-derived from scores', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$enrollment, $term, $results] = reportCardContext(1, ResultStatus::PUBLISHED);
    $result = $results->first();

    // Corrupt the underlying score AFTER publication without recompiling - if the report card
    // were recalculating instead of reading the stored Result, this would change the output.
    Score::query()->where('enrollment_id', $enrollment->id)->update(['score' => 0]);

    $response = withToken($token)->getJson("/api/v1/report-cards/enrollments/{$enrollment->id}/terms/{$term->id}")->assertOk();

    expect($response->json('data.subjects.0.percentage'))->toBe((string) $result->percentage);
});

it('reports academic session, term, class and section correctly', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$enrollment, $term] = reportCardContext(1, ResultStatus::PUBLISHED);

    withToken($token)->getJson("/api/v1/report-cards/enrollments/{$enrollment->id}/terms/{$term->id}")
        ->assertOk()
        ->assertJsonPath('data.enrollment.academic_session.id', $enrollment->academic_session_id)
        ->assertJsonPath('data.enrollment.school_class.id', $enrollment->school_class_id)
        ->assertJsonPath('data.enrollment.section.id', $enrollment->section_id)
        ->assertJsonPath('data.term.id', $term->id)
        ->assertJsonPath('data.term.academic_session.id', $term->academic_session_id);
});

it('reports the correct student identity', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$enrollment, $term] = reportCardContext(1, ResultStatus::PUBLISHED);

    withToken($token)->getJson("/api/v1/report-cards/enrollments/{$enrollment->id}/terms/{$term->id}")
        ->assertOk()
        ->assertJsonPath('data.enrollment.student.id', $enrollment->student_id);
});

it('computes overall_percentage as the plain arithmetic mean of subject percentages', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$enrollment, $term, $results] = reportCardContext(2, ResultStatus::PUBLISHED);

    $expected = number_format((float) $results->avg('percentage'), 2, '.', '');

    withToken($token)->getJson("/api/v1/report-cards/enrollments/{$enrollment->id}/terms/{$term->id}")
        ->assertOk()
        ->assertJsonPath('data.summary.subjects_count', 2)
        ->assertJsonPath('data.summary.overall_percentage', $expected);
});

it('excludes subjects with no grade_point from average_grade_point rather than treating them as zero', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    // No grading scale configured for this class level, so every subject's grade_point stays
    // null even though each is fully COMPLETE and PUBLISHED.
    [$enrollment, $term] = reportCardContext(2, ResultStatus::PUBLISHED);

    withToken($token)->getJson("/api/v1/report-cards/enrollments/{$enrollment->id}/terms/{$term->id}")
        ->assertOk()
        ->assertJsonPath('data.summary.average_grade_point', null);
});

it('resolves grade and grade_point through the class level\'s grading scale, matching the Result rows exactly', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $class = selectableSchoolClass();
    GradingScale::factory()->configureWithStandardBands()->create(['class_level_id' => $class->class_level_id]);

    // Rebuild the context manually so the grading scale exists BEFORE compilation.
    $session = eligibleSession();
    $term = Term::factory()->forSession($session, 1)->create();
    $section = Section::factory()->within($class, 'A', 'A')->create();
    $enrollment = activeEnrollment(['school_class_id' => $class->id, 'section_id' => $section->id, 'academic_session_id' => $session->id]);
    $classSubject = activeClassSubject(['school_class_id' => $class->id]);
    $assessment = activeAssessment(['class_subject_id' => $classSubject->id, 'term_id' => $term->id, 'max_score' => 20]);
    Score::factory()->forAssessment($assessment)->forEnrollment($enrollment)->create(['score' => 15]);

    $admin = userWithRole(Role::ADMIN);
    $service = app(ResultService::class);
    $result = $service->compile(['enrollment_id' => $enrollment->id, 'class_subject_id' => $classSubject->id, 'term_id' => $term->id], $admin);
    $result = $service->submit($result, $admin);
    $result = $service->approve($result, $admin);
    $result = $service->publish($result, $admin);

    withToken($token)->getJson("/api/v1/report-cards/enrollments/{$enrollment->id}/terms/{$term->id}")
        ->assertOk()
        ->assertJsonPath('data.subjects.0.percentage', '75.00')
        ->assertJsonPath('data.subjects.0.grade', 'A')
        ->assertJsonPath('data.subjects.0.grade_point', '5.00')
        ->assertJsonPath('data.subjects.0.remark', 'Excellent');
});

/*
| Historical safety
*/

it('remains stable after a later, unrelated grading-scale change', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    $class = selectableSchoolClass();
    $scale = GradingScale::factory()->configureWithStandardBands()->create(['class_level_id' => $class->class_level_id]);

    $session = eligibleSession();
    $term = Term::factory()->forSession($session, 1)->create();
    $section = Section::factory()->within($class, 'A', 'A')->create();
    $enrollment = activeEnrollment(['school_class_id' => $class->id, 'section_id' => $section->id, 'academic_session_id' => $session->id]);
    $classSubject = activeClassSubject(['school_class_id' => $class->id]);
    $assessment = activeAssessment(['class_subject_id' => $classSubject->id, 'term_id' => $term->id, 'max_score' => 20]);
    Score::factory()->forAssessment($assessment)->forEnrollment($enrollment)->create(['score' => 15]);

    $admin = userWithRole(Role::ADMIN);
    $service = app(ResultService::class);
    $result = $service->compile(['enrollment_id' => $enrollment->id, 'class_subject_id' => $classSubject->id, 'term_id' => $term->id], $admin);
    $result = $service->submit($result, $admin);
    $result = $service->approve($result, $admin);
    $result = $service->publish($result, $admin);

    $before = withToken($token)->getJson("/api/v1/report-cards/enrollments/{$enrollment->id}/terms/{$term->id}")->assertOk();

    // Retire the grading scale entirely - a later, unrelated configuration change.
    $scale->forceFill(['status' => CatalogStatus::ARCHIVED])->save();

    $after = withToken($token)->getJson("/api/v1/report-cards/enrollments/{$enrollment->id}/terms/{$term->id}")->assertOk();

    expect($after->json('data.subjects.0.grade'))->toBe($before->json('data.subjects.0.grade'))
        ->and($after->json('data.subjects.0.percentage'))->toBe($before->json('data.subjects.0.percentage'));
});

it('remains stable for a locked result even after later score and assessment changes', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$enrollment, $term, $results] = reportCardContext(1, ResultStatus::LOCKED);
    $result = $results->first();

    $before = withToken($token)->getJson("/api/v1/report-cards/enrollments/{$enrollment->id}/terms/{$term->id}")->assertOk();

    Score::query()->where('enrollment_id', $enrollment->id)->update(['score' => 1]);

    $after = withToken($token)->getJson("/api/v1/report-cards/enrollments/{$enrollment->id}/terms/{$term->id}")->assertOk();

    expect($after->json('data'))->toBe($before->json('data'))
        ->and($result->refresh()->status)->toBe(ResultStatus::LOCKED);
});

/*
| Response structure / security
*/

it('exposes no per-assessment breakdown or account internals', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$enrollment, $term] = reportCardContext(1, ResultStatus::PUBLISHED);

    $response = withToken($token)->getJson("/api/v1/report-cards/enrollments/{$enrollment->id}/terms/{$term->id}")->assertOk();

    expect($response->json('data.subjects.0'))
        ->not->toHaveKey('assessments')
        ->not->toHaveKey('scores')
        ->and($response->json('data.enrollment.student'))
        ->not->toHaveKey('email')
        ->not->toHaveKey('password');
});

it('answers 405 for POST/PUT/DELETE against the report card endpoints - it is read-only', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));
    [$enrollment, $term] = reportCardContext(1, ResultStatus::PUBLISHED);

    withToken($token)->postJson("/api/v1/report-cards/enrollments/{$enrollment->id}/terms/{$term->id}")->assertStatus(405);
    withToken($token)->putJson("/api/v1/report-cards/enrollments/{$enrollment->id}/terms/{$term->id}", [])->assertStatus(405);
    withToken($token)->deleteJson("/api/v1/report-cards/enrollments/{$enrollment->id}/terms/{$term->id}")->assertStatus(405);
});

/*
| Performance
*/

it('does not generate N+1 queries as the number of subjects on a report card grows', function (): void {
    $token = loginAs(userWithRole(Role::ADMIN));

    [$small, $smallTerm] = reportCardContext(1, ResultStatus::PUBLISHED);
    [$large, $largeTerm] = reportCardContext(8, ResultStatus::PUBLISHED);

    DB::enableQueryLog();
    withToken($token)->getJson("/api/v1/report-cards/enrollments/{$small->id}/terms/{$smallTerm->id}")->assertOk();
    $smallCount = count(DB::getQueryLog());
    DB::flushQueryLog();

    withToken($token)->getJson("/api/v1/report-cards/enrollments/{$large->id}/terms/{$largeTerm->id}")->assertOk();
    $largeCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    // The query count stays flat as subjects grow from 1 to 8 - eager loading
    // (classSubject.subject, classSubject.schoolClass) means the number of RESULT rows never
    // changes how many QUERIES are issued. A small, constant tolerance absorbs incidental,
    // non-scaling overhead (e.g. Sanctum's own token bookkeeping); a genuine per-subject query
    // would instead move $largeCount by roughly the extra 7 subjects, far past this margin.
    expect($largeCount)->toBeLessThanOrEqual($smallCount + 2);
});
