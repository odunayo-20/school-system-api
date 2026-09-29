<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The compiled academic outcome for one student's enrollment, in one class subject, for one
 * term: "Amina Yusuf - Mathematics - First Term - 87.50% - A".
 *
 * References enrollment_id, NOT student_id - the identical discipline scores already applies
 * (see the scores migration): a result belongs to the student's authoritative placement for
 * the session the term falls in, not merely to the person, so a JSS 1 result can never
 * accidentally surface against a later JSS 2 enrollment for the same student.
 *
 * class_subject_id and term_id are BOTH independently necessary, not derivable from each
 * other or from the enrollment: a class subject is a standing curriculum fact with no session
 * or term of its own (Module 07's own design), and an enrollment is scoped to a whole academic
 * SESSION, not to any one of its terms (Module 06's own design - a student's placement spans
 * every term of the session it names). A result is the one row in this project that needs all
 * three coordinates at once: WHICH student placement, WHICH subject, WHICH term.
 *
 * ONE TABLE, not a parent Result plus child ResultItem rows: this project's own domain stops
 * at "one student's one subject result for one term" - there is no cross-subject aggregate
 * (an overall term average, a class position) in scope for this module, so there is no second,
 * genuinely distinct concept for a parent envelope to hold. See the Module 12 audit for why a
 * two-table design was considered and rejected.
 *
 * THIS TABLE DELIBERATELY HOLDS NO raw total, no per-assessment breakdown, and no snapshot of
 * the scores that produced it. Assessment Scores remain the sole authoritative raw input -
 * see the scores migration - and a raw sum of differently-scaled assessments (a 20-mark CA
 * added to a 100-mark exam) has no honest standalone meaning once weighting is in play, so
 * storing one would be exactly the "ambiguous total field" this module's own brief warns
 * against. A client wanting the breakdown queries GET /scores with the same enrollment_id,
 * subject_id and term_id filters Module 10 already exposes.
 *
 * unique(enrollment_id, class_subject_id, term_id) is the database-level half of "one compiled
 * result per student, per subject, per term" - ResultService enforces the same rule by
 * updating the existing row on a repeat compile rather than ever attempting a second insert,
 * with this index as the race-safe backstop for two concurrent compiles of the same context.
 *
 * Every foreign key is restrictOnDelete, matching every foreign key introduced since Module
 * 05: a result is exactly the kind of academic-history anchor a future
 * approval/publication/report-card module will reference, so none of the three references it
 * names can be silently deleted out from under it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('results', function (Blueprint $table) {
            $table->id();

            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();
            $table->foreignId('class_subject_id')->constrained('class_subjects')->restrictOnDelete();
            $table->foreignId('term_id')->constrained('terms')->restrictOnDelete();

            // The final, weighted (or, absent any configured weight, plain score/max-score)
            // percentage - see ResultService for the exact formula and why it is never
            // renormalized when some assessments are still unscored. decimal(5,2), matching
            // grading_scale_items.min_percentage/max_percentage's own precedent for the
            // identical shape of value.
            $table->decimal('percentage', 5, 2);

            // Resolved through Module 11's grading scale for the enrollment's class level -
            // all three null together whenever the result is INCOMPLETE, or no covering band
            // exists, or no grading scale is configured at all. Never guessed, never a
            // hardcoded rule - see ResultService::calculateOutcome().
            $table->string('grade', 10)->nullable();
            $table->decimal('grade_point', 4, 2)->nullable();
            $table->string('remark', 100)->nullable();

            $table->string('status', 20)->default('INCOMPLETE')->index();

            $table->timestamps();

            // The database-level guarantee behind "one compiled result per student, subject
            // and term". See the class docblock.
            $table->unique(['enrollment_id', 'class_subject_id', 'term_id']);

            // The query this module's own bulk compile and list filters actually need
            // answered: "every result for this class subject, this term" - a class teacher
            // reviewing their own compiled roster. enrollment_id alone is already served by
            // the unique index's own leftmost column, so no separate index repeats it.
            $table->index(['class_subject_id', 'term_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('results');
    }
};
