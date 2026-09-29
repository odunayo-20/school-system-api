<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a specific student obtained against a specific configured assessment: "Student 123 -
 * Mathematics CA 1 - 17".
 *
 * References assessment_id + enrollment_id, NOT student_id. A score belongs to the STUDENT'S
 * AUTHORITATIVE PLACEMENT for the session the assessment falls in - enrollments.id, not
 * students.id directly - the identical discipline teacher_assignments already applies via
 * class_subject_id: a fact this specific must name the specific historical context it happened
 * in, not merely the person, so a JSS 1 score can never accidentally surface against a later
 * JSS 2 enrollment for the same student. See the enrollments migration for why a student
 * accumulates one row per session rather than one mutable "current placement".
 *
 * THIS TABLE DELIBERATELY HOLDS NO max_score, percentage, grade, or student_id column.
 * max_score belongs to the assessment (assessments.max_score) and is read fresh from there on
 * every write, never trusted from a client and never copied here - copying it would let an
 * assessment's own max_score change later (a legitimate correction through
 * UpdateAssessmentRequest) silently drift out of step with a score already recorded against
 * it. percentage is computable at read time (score / assessment.max_score) and storing it
 * would be a cache with no invalidation path. student_id is reachable through
 * enrollment.student_id - storing it again would be the exact duplication class_subjects
 * already avoids for class_id/subject_id once class_subject_id exists to name both. Grading,
 * GPA, pass/fail and report cards are explicitly a later module's concern - this table stores
 * a raw mark and nothing that interprets it.
 *
 * unique(assessment_id, enrollment_id) is the database-level half of "a student's enrollment
 * gets at most one score per assessment" - the form request enforces the same rule with a
 * friendly 422 first; the index is what makes it true under a race between two concurrent
 * submissions for the same pair. Retries/attempts, if the domain ever needs them, are a
 * decision for a future module to make explicitly (an attempt_number column or a new table),
 * not something this module should half-build by leaving the constraint loose today.
 *
 * Both foreign keys are restrictOnDelete, matching every foreign key introduced since Module
 * 05: a score is exactly the kind of academic-history anchor a future grading/result module
 * will reference, so neither the assessment nor the enrollment named here can be silently
 * deleted out from under it. (Assessment and Enrollment both already have no delete endpoint
 * of their own, but the constraint is the honest expression of the relationship regardless.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scores', function (Blueprint $table) {
            $table->id();

            $table->foreignId('assessment_id')->constrained('assessments')->restrictOnDelete();
            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();

            // Matches assessments.max_score's own decimal(6,2) precedent: a score is bounded
            // by that column's value, so the same range and precision are appropriate here.
            // decimal, not float, so a value like 17.5 is stored exactly rather than as a
            // binary approximation - the same reasoning assessments.max_score/weight already
            // apply.
            $table->decimal('score', 6, 2);

            // Free-form: why a score was corrected, whether a student was absent and awarded
            // zero, or any context worth keeping with the mark - matching the notes/remarks
            // convention every prior module carries on its own working record.
            $table->text('remarks')->nullable();

            $table->timestamps();

            // The database-level guarantee behind "one score per assessment per enrollment".
            // See the class docblock. Composite, not two separate unique columns, because
            // neither column is unique on its own - many students share an assessment, and
            // one enrollment accumulates a score per assessment across a term.
            $table->unique(['assessment_id', 'enrollment_id']);

            // The query this module actually needs answered that the unique index above
            // cannot serve on its own: "every score for this enrollment", the shape a future
            // student-facing or report-card read uses. The unique index's own leftmost column
            // is assessment_id, so it cannot serve an enrollment_id-only lookup efficiently -
            // this index is what does. A standalone assessment_id index is NOT added
            // separately: the unique index's leftmost column already serves "every score for
            // this assessment" (a class roster read) without a second index repeating it.
            $table->index('enrollment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scores');
    }
};
