<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The authoritative academic placement: which student sits in which class and section, for
 * one academic session.
 *
 * THIS TABLE, NOT `students`, IS WHERE A PLACEMENT LIVES. The students migration explains at
 * length why `students` carries no `current_class_id`/`current_section_id`/`current_session_id`
 * - this is the table that reasoning was pointing at. A student's placement history is a
 * sequence of rows here, one per session:
 *
 *     2024/2025 -> JSS 2 -> A
 *     2025/2026 -> JSS 3 -> A
 *     2026/2027 -> SS 1  -> B
 *
 * Each row is written once and never repointed at a different session, class or section.
 * Promotion (a future module) creates a NEW row for the new session; it never mutates an
 * existing one, so this table is never rewritten by an academic event - only appended to,
 * or (rarely) marked WITHDRAWN/CANCELLED in place. See EnrollmentService for why those two
 * are the only writes a non-creation call can make.
 *
 * `unique(student_id, academic_session_id)` is the database-level half of "one student, one
 * authoritative enrollment per session" - the form request enforces the same rule with a
 * friendly 422 first, but the index is what makes it true even under a race between two
 * concurrent creates for the same student and session.
 *
 * Every foreign key is `restrictOnDelete`. Unlike `students.user_id` (a login a person
 * outlives) or `admissions.student_id` (a link nothing yet depends on), an enrollment is
 * referenced academic history: a future results/attendance/report-card module will hang data
 * off this row, and off the student/session/class/section it names. Losing that silently to a
 * cascade would corrupt history that cannot be reconstructed; refusing the delete is the only
 * safe default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('academic_session_id')->constrained('academic_sessions')->restrictOnDelete();

            // Named school_class_id, matching sections.school_class_id exactly - the
            // established convention this project already uses because SchoolClass is the
            // model behind the "classes" table (Class is a reserved word).
            $table->foreignId('school_class_id')->constrained('classes')->restrictOnDelete();
            $table->foreignId('section_id')->constrained('sections')->restrictOnDelete();

            // The date the placement actually took effect, distinct from created_at (the
            // data-entry moment). Required: a registrar enrolling a student a week after
            // they started attending needs to record the real date, not today's.
            $table->date('enrollment_date');

            $table->string('status', 20)->default('ACTIVE')->index();

            // Free-form: a reason for a withdrawal or a cancellation, or any context worth
            // keeping with the placement. One field, matching admissions.notes.
            $table->text('notes')->nullable();

            // When the status last left ACTIVE. Null while ACTIVE. Kept separate from
            // updated_at, which also changes on an ordinary notes/enrollment_date amend and
            // would not answer "when was this withdrawn" once the record is terminal anyway.
            $table->timestamp('status_changed_at')->nullable();

            $table->timestamps();

            // The database-level guarantee behind "one authoritative enrollment per student
            // per session", regardless of that enrollment's status. See the class docblock.
            $table->unique(['student_id', 'academic_session_id']);

            // The roster questions this module actually answers: who is in this class, who
            // is in this class's section. Composite because both questions name the class.
            $table->index(['school_class_id', 'section_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};
