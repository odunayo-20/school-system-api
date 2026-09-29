<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reusable grading scheme, scoped to one educational stage: "Junior Secondary Standard",
 * "Senior Secondary WAEC Style".
 *
 * class_level_id is REQUIRED, not nullable, on purpose - see the class docblock on GradingScale
 * for the full reasoning. The alternative (a nullable "whole-school default" scope) was
 * considered and rejected: this project's own single-active-record technique
 * (TracksSingleActiveRecord, already used by schools/academic_sessions/terms) relies on a
 * composite unique index treating every SCOPING column as NOT NULL, with only the trailing
 * active_marker column carrying the nullable-for-distinctness trick. Standard SQL does not
 * treat two NULLs as equal for uniqueness (SQL Server is the sole, inconsistent exception), so
 * a nullable class_level_id would make "at most one ACTIVE scale per scope" silently
 * unenforceable on the very drivers this project targets. A school wanting one uniform scheme
 * across levels defines the same ranges under each level's own scale - explicit, and portable
 * across every configured database engine, rather than an implicit fallback that only some
 * drivers would actually guarantee.
 *
 * Structurally close to a twin of subjects/assessment_types (name, code, sort_order,
 * CatalogStatus retirement rather than deletion), with one addition: active_marker plus a
 * composite unique index gives "at most one ACTIVE scale per class level" as a database fact,
 * the identical technique Module 08 introduced for a scoped singleton and Module 02 already
 * uses for a school-wide one.
 *
 * THIS TABLE DELIBERATELY HOLDS NO min/max/grade/grade_point/remark column - those belong to
 * grading_scale_items, one scale to many ranges, because a scale's ranges are validated and
 * read together as a group, never usefully in isolation.
 *
 * NO DELETE ENDPOINT is exposed for this table - see GradingScaleController. A scale is
 * exactly the kind of academic-policy anchor a future grading/result compilation module will
 * reference to interpret a historical result; restrictOnDelete on class_level_id matches every
 * foreign key introduced since Module 05 for the identical reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grading_scales', function (Blueprint $table) {
            $table->id();

            $table->foreignId('class_level_id')->constrained('class_levels')->restrictOnDelete();

            $table->string('name', 100);
            $table->string('code', 20);
            $table->smallInteger('sort_order')->default(0)->index();
            $table->string('status', 20)->default('ACTIVE');

            // See TracksSingleActiveRecord: 1 while status is ACTIVE, NULL otherwise - never
            // accepted from a client, absent from every API resource, derived from status
            // alone by the model's own saving hook.
            $table->boolean('active_marker')->nullable();

            $table->timestamps();

            // name and code are unique WITHIN a class level, not globally - two different
            // class levels may each have their own "Standard" scale, matching the identical
            // scoped-uniqueness reasoning sections/classes already apply one level up.
            $table->unique(['class_level_id', 'name']);
            $table->unique(['class_level_id', 'code']);

            // The database-level guarantee behind "at most one ACTIVE grading scale per class
            // level" - the form request enforces the same rule with a friendly 422 first (a
            // scoped Rule::unique on status=ACTIVE would need a raw subquery a plain rule
            // cannot express cleanly); this index is what makes it true even under a race
            // between two concurrent activations for the same level.
            $table->unique(['class_level_id', 'active_marker']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grading_scales');
    }
};
