<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One percentage band within a grading scale: "70 to 100 -> A, grade point 5, Excellent".
 *
 * Boundaries are INCLUSIVE at both ends: min_percentage <= percentage <= max_percentage. Two
 * adjacent, non-overlapping bands are therefore written as, for example, 60.00-69.99 and
 * 70.00-100.00 - not 60-70 and 70-100, which would double-cover 70.00 itself. This is a
 * GradingService-enforced rule (see ValidatesGradingScaleRecord), not a database CHECK
 * constraint: SQLite (the test driver) enforces CHECK inconsistently across ALTER paths, the
 * identical reasoning the assessments migration already gives for skipping one on max_score,
 * so overlap/range validation is deliberately an application-layer guarantee here too.
 *
 * NO sort_order column, unlike every other catalogue-style table in this project
 * (class_levels, subjects, assessment_types). A grading item's percentage range already gives
 * it an unambiguous, intrinsic display order - min_percentage descending, highest band first -
 * so a second, independently-editable ordering field would be redundant complexity with no
 * genuine use this module's own brief calls for.
 *
 * grading_scale_id is cascadeOnDelete, NOT restrictOnDelete, unlike every foreign key
 * introduced since Module 05. An item has no meaning outside its scale - it is a component of
 * the scale's own configuration, not an independent academic-history anchor a future module
 * will reference directly (a future result will reference the ITEM the percentage matched, if
 * anything, by copying its resolved grade/grade_point/remark onto the result row at compile
 * time - see the Module 11 audit's "what this module owes the next one" - not by holding a
 * live foreign key back to this row). Since GradingScale itself has no delete endpoint, this
 * only ever fires for a direct database operation outside the API, where cascading is the
 * referentially correct behaviour rather than an orphaned row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grading_scale_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('grading_scale_id')->constrained('grading_scales')->cascadeOnDelete();

            $table->string('grade', 10);

            // decimal(5,2), matching assessments.weight's own precedent from Module 09 for the
            // identical shape of value: a 0-100 percentage, not float, so a boundary like
            // 69.99 is stored exactly rather than as a binary approximation.
            $table->decimal('min_percentage', 5, 2);
            $table->decimal('max_percentage', 5, 2);

            $table->decimal('grade_point', 4, 2)->nullable();
            $table->string('remark', 100)->nullable();

            $table->timestamps();

            // "Every band in one scale must be distinguishable by grade" - grade is unique
            // WITHIN a scale, not globally, matching the identical scoped-uniqueness reasoning
            // every parent/child table in this project already applies.
            $table->unique(['grading_scale_id', 'grade']);

            // The query this module actually needs answered: "which band covers this
            // percentage, for this scale" - a scale is always known before a lookup runs (see
            // GradingService::calculate()), so the index leads with grading_scale_id.
            $table->index(['grading_scale_id', 'min_percentage', 'max_percentage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grading_scale_items');
    }
};
