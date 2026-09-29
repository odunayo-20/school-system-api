<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One configured assessment: "Mathematics - First Term - JSS 2 - CA 1".
 *
 * References class_subject_id + term_id, NOT a separate academic_session_id. A term's own
 * academic_session_id is a real, first-class column (see the terms table), so the session is
 * always reachable through term.academicSession - duplicating it here would be the exact
 * denormalisation Module 08 already avoided by NOT storing school_class_id/subject_id
 * directly on teacher_assignments once class_subject_id existed to name both at once.
 *
 * assessment_type_id names the CATEGORY ("CA", "Test"); name is the actual instance within
 * that category ("CA 1", "CA 2"). Both are needed together: a class subject/term legitimately
 * has several assessments of the same type (CA1, CA2, CA3 all category "CA"), so the type
 * alone cannot be the uniqueness key - see the unique index below.
 *
 * THIS TABLE DELIBERATELY HOLDS NO score, grade or result column, and there is no scores
 * table yet. Recording a pupil's mark against one of these rows, and compiling marks into a
 * grade or a result, are Module 10 (Assessment Scores) and later modules' concerns - this
 * module configures WHAT will be scored, not the scores themselves.
 *
 * class_subject_id, term_id and assessment_type_id are all restrictOnDelete and have no
 * mutation path once created (see UpdateAssessmentRequest): this row is exactly the kind of
 * academic-history anchor a future score will reference, and repointing which class subject,
 * term or category an assessment named after scores exist against it would silently corrupt
 * what those scores mean - the identical reasoning class_subjects and teacher_assignments
 * already apply to their own identity columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('class_subject_id')->constrained('class_subjects')->restrictOnDelete();
            $table->foreignId('term_id')->constrained('terms')->restrictOnDelete();
            $table->foreignId('assessment_type_id')->constrained('assessment_types')->restrictOnDelete();

            $table->string('name', 100);

            // The ceiling a score against this assessment may not exceed - validated to be
            // greater than zero at the application layer (see ValidatesAssessmentRecord); a
            // CHECK constraint was considered and skipped, since SQLite (the test driver)
            // enforces CHECK inconsistently across ALTER paths and every numeric boundary
            // already validated this way elsewhere in the project (term_number, sort_order)
            // relies on the application layer alone.
            $table->decimal('max_score', 6, 2);

            // This assessment's share of the class subject's overall grade for the term, as a
            // percentage. Nullable and DELIBERATELY NOT constrained to sum to 100 across the
            // assessments in a class subject/term: weighting policy (whether it must total
            // 100%, whether some assessments carry no weight at all) is a grading-scheme
            // decision Module 11+ owns, not a fact this configuration step can enforce without
            // knowing the whole scheme in advance.
            $table->decimal('weight', 5, 2)->nullable();

            $table->smallInteger('sort_order')->default(0)->index();
            $table->string('status', 20)->default('ACTIVE');

            $table->timestamps();

            // The database-level half of "CA1 and CA2 may coexist, but the same name cannot be
            // configured twice for the same class subject and term" - the form request enforces
            // the same rule with a friendly 422 first; this index is what makes it true under a
            // race between two concurrent creates for the same triple.
            $table->unique(['class_subject_id', 'term_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessments');
    }
};
