<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The offering of a subject to a class: "JSS 2 teaches Mathematics".
 *
 * NOT the subject itself (subjects) and NOT a teacher assignment - that belongs to a future
 * module that will reference THIS table's id, not subjects.id directly, so a teacher is
 * always assigned to "Mathematics as taught in JSS 2" rather than to "Mathematics" in the
 * abstract. THIS TABLE DELIBERATELY HOLDS NO teacher_id, staff_id, assessment_id or score
 * column - see the migration for `subjects` for the identical reasoning applied one level
 * up.
 *
 * Attached to classes (school_class_id), NOT class_levels. A curriculum genuinely differs
 * per class year, not merely per broad stage: JSS 2 and JSS 3 both sit inside the Junior
 * Secondary level but do not offer identical subjects, so scoping to the level would be too
 * coarse to be true. This mirrors Module 06's own choice of school_class_id as the
 * placement granularity for enrollments.
 *
 * NO academic_session_id. Unlike an enrollment, which the same person needs a new row for
 * every year, a class's curriculum is a standing fact about the class - classes themselves
 * carry no session either - and re-declaring "JSS 2 teaches Mathematics" every single year
 * would be pure duplication with no rule in this module depending on the session.
 *
 * unique(school_class_id, subject_id) is the database-level half of "a class should not
 * have the same subject attached twice" - the form request enforces the same rule with a
 * friendly 422 first; the index is what makes it true under a race between two concurrent
 * creates for the same pair.
 *
 * Both foreign keys are restrictOnDelete, matching every foreign key introduced by Module 05
 * and Module 06: this row is the anchor a future Assessment table will reference, so neither
 * its class nor its subject can be silently deleted out from under it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_class_id')->constrained('classes')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->string('status', 20)->default('ACTIVE')->index();
            $table->timestamps();

            $table->unique(['school_class_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_subjects');
    }
};
