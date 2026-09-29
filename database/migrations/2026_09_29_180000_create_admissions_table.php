<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The school's decision record for one applicant, targeting one academic session.
 *
 * THIS IS NOT A STUDENT RECORD. An admission is the process by which somebody BECOMES a
 * pupil; the identity fields below are a snapshot of who applied, kept here only until (and
 * unless) the decision is ADMITTED. Once admitted, the pupil's own identity lives on the
 * `students` row that this admission created - see `student_id` below - and this row is never
 * read again as a source of truth about that person. See the students migration's own note on
 * why `admission_number` was excluded from that table: an admission identifies one attempt,
 * and a person can have more than one (apply, be refused, apply again; or transfer in and be
 * admitted a second time).
 *
 * THIS IS NOT AN ENROLLMENT RECORD either. `entry_class_level_id` records the level the
 * applicant is seeking - a fact about the application - and is never copied onto the pupil it
 * creates and never promoted into a class/section assignment. The authoritative placement for
 * a specific academic session is a future `enrollments` table's job, exactly as the students
 * migration reserves that table for a pupil's placement history.
 *
 * `student_id` is nullable, unique, and - unlike every other foreign key a request could ever
 * populate in this module - it is set by NOTHING a client sends. AdmissionService::admit() is
 * the only writer, inside a transaction, at the moment a Student is created. A deny-list of
 * forbidden request fields is a list somebody has to remember to extend; the absence of any
 * path that accepts this column is what actually prevents a holder of admissions.update from
 * linking (or relinking) an admission to an arbitrary existing pupil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admissions', function (Blueprint $table) {
            $table->id();

            // See the note above: never accepted from a request, written only by
            // AdmissionService::admit(). nullOnDelete for the same reason
            // students.user_id uses it - a pupil (and here, the admission record that
            // produced them) outlives the row it points to going away, though in
            // practice nothing in this project ever deletes a student.
            $table->foreignId('student_id')->nullable()->unique()->constrained('students')->nullOnDelete();

            // The intake this applicant is being considered for. restrictOnDelete, the same
            // convention as terms.academic_session_id: a session that still has admissions
            // against it cannot be removed without orphaning them.
            $table->foreignId('academic_session_id')->constrained('academic_sessions')->restrictOnDelete();

            // The level the applicant is seeking, not an authoritative placement. Nullable:
            // a school may record an application before a level has been decided, and not
            // every admission needs one. restrictOnDelete for the same reason as above.
            $table->foreignId('entry_class_level_id')->nullable()->constrained('class_levels')->restrictOnDelete();

            // This admission's own reference, derived from its own primary key exactly like
            // students.student_number and staff.staff_number - nullable-then-derived,
            // never count() + 1. See AdmissionService::reservationNumber().
            $table->string('admission_number')->nullable()->unique();

            // A snapshot of the applicant's identity. Deliberately duplicated from what will
            // become the pupil's own fields rather than referenced, because before ADMITTED
            // there is no Student row to reference - the person is not yet a pupil.
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 20)->nullable();

            $table->string('status', 20)->default('PENDING')->index();

            // Free-form: why an application was rejected, a note on a withdrawal, or any
            // context a registrar wants attached to the decision. One field rather than a
            // family of reason columns, because nothing in this module distinguishes their
            // structure beyond "a note on the decision".
            $table->text('notes')->nullable();

            // When the status left PENDING. Null while PENDING. Kept separate from
            // updated_at, which changes on every amend and would not answer "when was this
            // decided" once the record can no longer be amended anyway.
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admissions');
    }
};
