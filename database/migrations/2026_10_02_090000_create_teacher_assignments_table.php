<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which teaching staff member is responsible for a class subject, for one academic session:
 * "Teacher A teaches JSS 2 Mathematics in 2026/2027".
 *
 * SESSION-SCOPED, unlike class_subjects. A class subject ("JSS 2 teaches Mathematics") is a
 * standing curriculum fact; WHO teaches it is not - the same person rarely teaches the same
 * class subject forever, and the module's own brief describes exactly this scenario: Teacher
 * A this year, Teacher B next (or mid-year). This mirrors enrollments' own relationship to
 * classes: a session-scoped placement into an otherwise-standing structure.
 *
 * ONE ACTIVE ASSIGNMENT PER (class_subject, session) AT A TIME. Team teaching / assistant
 * teachers were considered and deliberately not built: nothing in the brief requires it, and
 * inventing an unrequested "primary vs assistant" role column to support it would be
 * speculative architecture. The single-active-slot rule is enforced by active_marker below,
 * the identical singleton technique academic_sessions/terms/schools already use for "at most
 * one current record" - applied here to a SCOPED group (per class_subject + session) for the
 * first time in this project, by including the scope columns in the same unique index rather
 * than by inventing a new mechanism.
 *
 * active_marker is 1 only while status is ACTIVE, NULL otherwise - never accepted from a
 * client, absent from every API resource, derived from status alone (see
 * TeacherAssignmentService). A completed reassignment therefore looks like: the old row's
 * active_marker is cleared to NULL when it ends, freeing the (class_subject_id,
 * academic_session_id) pair for a new row to claim NULL-then-1 in its place - and the old row
 * is NEVER deleted or repointed, so "who taught this before" remains answerable forever.
 *
 * Both restrictOnDelete, matching every foreign key introduced by Module 05 onward: this row
 * is exactly the kind of academic-history anchor a future assessment/score/result chain will
 * reference, so neither the staff member nor the class subject named here can be silently
 * deleted out from under it. (staff has no delete endpoint at all, but the constraint is the
 * honest expression of the relationship regardless.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('teaching_staff_id')->constrained('staff')->restrictOnDelete();
            $table->foreignId('class_subject_id')->constrained('class_subjects')->restrictOnDelete();
            $table->foreignId('academic_session_id')->constrained('academic_sessions')->restrictOnDelete();

            $table->string('status', 20)->default('ACTIVE')->index();

            // See the class docblock: 1 while ACTIVE, NULL once ENDED or CANCELLED. The
            // uniqueness that matters is expressed entirely through the composite index
            // below - this column carries no meaning of its own outside that index.
            $table->boolean('active_marker')->nullable();

            // Free-form: why an assignment ended, or any context worth keeping with it -
            // matching enrollments.notes and admissions.notes.
            $table->text('notes')->nullable();

            // When the status left ACTIVE. Null while ACTIVE. Kept separate from updated_at,
            // which also changes on an ordinary notes amend.
            $table->timestamp('ended_at')->nullable();

            $table->timestamps();

            // The database-level guarantee behind "one active teacher per class subject per
            // session": a genuine value of 1 must be unique for a given
            // (class_subject_id, academic_session_id) pair, while any number of NULL rows
            // (ended or cancelled history) are permitted for the same pair - SQLite, MySQL,
            // PostgreSQL and SQL Server all treat NULL as distinct inside a unique index, the
            // identical portability property active_marker already relies on elsewhere in
            // this project.
            $table->unique(['class_subject_id', 'academic_session_id', 'active_marker']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_assignments');
    }
};
