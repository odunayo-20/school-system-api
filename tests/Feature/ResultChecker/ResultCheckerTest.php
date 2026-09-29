<?php

use App\Enums\ResultStatus;
use App\Models\Student;
use App\Models\Term;

/*
|--------------------------------------------------------------------------
| Public result checker: valid lookup, unpublished/unknown rejection,
| enumeration protection and throttling (Module 16)
|--------------------------------------------------------------------------
*/

function checkerStudent(array $attributes = []): Student
{
    return Student::factory()->number('STU-'.fake()->unique()->numerify('####'))->create(array_merge([
        'date_of_birth' => '2010-05-14',
    ], $attributes));
}

/*
| Valid lookup
*/

it('returns a published result for the correct student_number, date_of_birth and term', function (): void {
    $student = checkerStudent();
    [$enrollment, $term] = reportCardContext(2, ResultStatus::PUBLISHED, ['student_id' => $student->id]);

    $response = test()->postJson('/api/v1/result-checker', [
        'student_number' => $student->student_number,
        'date_of_birth' => '2010-05-14',
        'term_id' => $term->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.enrollment.id', $enrollment->id)
        ->assertJsonPath('data.enrollment.student.id', $student->id)
        ->assertJsonPath('data.term.id', $term->id)
        ->assertJsonCount(2, 'data.subjects');
});

it('returns a locked result the same way as a published one', function (): void {
    $student = checkerStudent();
    [, $term] = reportCardContext(1, ResultStatus::LOCKED, ['student_id' => $student->id]);

    test()->postJson('/api/v1/result-checker', [
        'student_number' => $student->student_number,
        'date_of_birth' => '2010-05-14',
        'term_id' => $term->id,
    ])->assertOk();
});

it('accepts a lower-case or differently spaced student number, matching how it is stored', function (): void {
    $student = checkerStudent();
    [, $term] = reportCardContext(1, ResultStatus::PUBLISHED, ['student_id' => $student->id]);

    test()->postJson('/api/v1/result-checker', [
        'student_number' => ' '.mb_strtolower($student->student_number).' ',
        'date_of_birth' => '2010-05-14',
        'term_id' => $term->id,
    ])->assertOk();
});

it('requires no authentication at all', function (): void {
    $student = checkerStudent();
    [, $term] = reportCardContext(1, ResultStatus::PUBLISHED, ['student_id' => $student->id]);

    // No Authorization header sent anywhere in this test - the request already succeeds.
    test()->postJson('/api/v1/result-checker', [
        'student_number' => $student->student_number,
        'date_of_birth' => '2010-05-14',
        'term_id' => $term->id,
    ])->assertOk();
});

/*
| Rejection - unpublished, unknown, mismatched, all behind one generic 404
*/

it('rejects an unpublished (COMPILED) result with the same 404 as a nonexistent one', function (): void {
    $student = checkerStudent();
    [, $term] = reportCardContext(1, ResultStatus::COMPILED, ['student_id' => $student->id]);

    test()->postJson('/api/v1/result-checker', [
        'student_number' => $student->student_number,
        'date_of_birth' => '2010-05-14',
        'term_id' => $term->id,
    ])->assertNotFound();
});

it('rejects a SUBMITTED result', function (): void {
    $student = checkerStudent();
    [, $term] = reportCardContext(1, ResultStatus::SUBMITTED, ['student_id' => $student->id]);

    test()->postJson('/api/v1/result-checker', [
        'student_number' => $student->student_number,
        'date_of_birth' => '2010-05-14',
        'term_id' => $term->id,
    ])->assertNotFound();
});

it('rejects an APPROVED result', function (): void {
    $student = checkerStudent();
    [, $term] = reportCardContext(1, ResultStatus::APPROVED, ['student_id' => $student->id]);

    test()->postJson('/api/v1/result-checker', [
        'student_number' => $student->student_number,
        'date_of_birth' => '2010-05-14',
        'term_id' => $term->id,
    ])->assertNotFound();
});

it('rejects a student number that does not exist, with the identical body a wrong date of birth gets', function (): void {
    $student = checkerStudent();
    [, $term] = reportCardContext(1, ResultStatus::PUBLISHED, ['student_id' => $student->id]);

    $unknown = test()->postJson('/api/v1/result-checker', [
        'student_number' => 'NO-SUCH-NUMBER',
        'date_of_birth' => '2010-05-14',
        'term_id' => $term->id,
    ]);

    $wrongDob = test()->postJson('/api/v1/result-checker', [
        'student_number' => $student->student_number,
        'date_of_birth' => '1999-01-01',
        'term_id' => $term->id,
    ]);

    $unknown->assertNotFound();
    $wrongDob->assertNotFound();
    expect($unknown->json())->toBe($wrongDob->json());
});

it('rejects a term the student was never enrolled for, with the same 404', function (): void {
    $student = checkerStudent();
    reportCardContext(1, ResultStatus::PUBLISHED, ['student_id' => $student->id]);

    $otherTerm = Term::factory()->create();

    test()->postJson('/api/v1/result-checker', [
        'student_number' => $student->student_number,
        'date_of_birth' => '2010-05-14',
        'term_id' => $otherTerm->id,
    ])->assertNotFound();
});

it('rejects a term id that does not exist as a plain validation error, not a lookup 404', function (): void {
    $student = checkerStudent();

    test()->postJson('/api/v1/result-checker', [
        'student_number' => $student->student_number,
        'date_of_birth' => '2010-05-14',
        'term_id' => 999999,
    ])->assertUnprocessable()->assertJsonValidationErrors('term_id');
});

it('validates required fields', function (): void {
    test()->postJson('/api/v1/result-checker', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['student_number', 'date_of_birth', 'term_id']);
});

/*
| IDOR / cross-student protection
*/

it('never returns a different student\'s result even when both share the same term', function (): void {
    $studentA = checkerStudent(['date_of_birth' => '2010-01-01']);
    $studentB = checkerStudent(['date_of_birth' => '2011-02-02']);

    [$enrollmentA, $term] = reportCardContext(1, ResultStatus::PUBLISHED, ['student_id' => $studentA->id]);
    reportCardContext(1, ResultStatus::PUBLISHED, ['student_id' => $studentB->id]);

    $response = test()->postJson('/api/v1/result-checker', [
        'student_number' => $studentA->student_number,
        'date_of_birth' => '2010-01-01',
        'term_id' => $term->id,
    ]);

    $response->assertOk()->assertJsonPath('data.enrollment.id', $enrollmentA->id);
});

it('does not leak a real student\'s existence through student B\'s date of birth', function (): void {
    $studentA = checkerStudent(['date_of_birth' => '2010-01-01']);
    $studentB = checkerStudent(['date_of_birth' => '2011-02-02']);
    [, $term] = reportCardContext(1, ResultStatus::PUBLISHED, ['student_id' => $studentA->id]);

    // studentA's own number with studentB's date of birth - must fail exactly like any other
    // mismatch, never partially succeed or reveal that the number belongs to someone else.
    test()->postJson('/api/v1/result-checker', [
        'student_number' => $studentA->student_number,
        'date_of_birth' => '2011-02-02',
        'term_id' => $term->id,
    ])->assertNotFound();
});

/*
| Sensitive data protection
*/

it('exposes no account internals through the student in the response', function (): void {
    $student = checkerStudent();
    [, $term] = reportCardContext(1, ResultStatus::PUBLISHED, ['student_id' => $student->id]);

    $response = test()->postJson('/api/v1/result-checker', [
        'student_number' => $student->student_number,
        'date_of_birth' => '2010-05-14',
        'term_id' => $term->id,
    ])->assertOk();

    $response->assertJsonMissingPath('data.enrollment.student.email')
        ->assertJsonMissingPath('data.enrollment.student.password');
});

/*
| Throttling
*/

it('throttles repeated result-checker attempts', function (): void {
    $student = checkerStudent();
    [, $term] = reportCardContext(1, ResultStatus::PUBLISHED, ['student_id' => $student->id]);

    foreach (range(1, 5) as $attempt) {
        test()->postJson('/api/v1/result-checker', [
            'student_number' => $student->student_number,
            'date_of_birth' => '1900-01-01',
            'term_id' => $term->id,
        ])->assertNotFound();
    }

    test()->postJson('/api/v1/result-checker', [
        'student_number' => $student->student_number,
        'date_of_birth' => '2010-05-14',
        'term_id' => $term->id,
    ])->assertStatus(429);
});

/*
| Response structure
*/

it('returns the same envelope and fields as the authenticated report card', function (): void {
    $student = checkerStudent();
    [, $term] = reportCardContext(2, ResultStatus::PUBLISHED, ['student_id' => $student->id]);

    test()->postJson('/api/v1/result-checker', [
        'student_number' => $student->student_number,
        'date_of_birth' => '2010-05-14',
        'term_id' => $term->id,
    ])->assertOk()->assertJsonStructure([
        'data' => [
            'enrollment', 'term',
            'subjects' => [['result_id', 'class_subject', 'percentage', 'grade', 'grade_point', 'remark', 'status']],
            'summary' => ['subjects_count', 'overall_percentage', 'average_grade_point'],
        ],
    ]);
});
