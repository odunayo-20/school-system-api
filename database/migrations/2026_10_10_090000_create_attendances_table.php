<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a specific student was present, absent, late or excused on a specific date, against
 * a specific enrollment - "Student 123 - 2026-09-29 - PRESENT".
 *
 * References enrollment_id, NOT student_id, matching scores' own precedent exactly: an
 * attendance mark belongs to the student's authoritative placement for the session it falls
 * in, not merely to the person, so a mark taken while enrolled in JSS 2 can never surface
 * against a later JSS 3 enrollment for the same student once Module 15 promotes them. See the
 * enrollments and scores migrations for the fuller reasoning this table inherits unchanged.
 *
 * ACADEMIC_SESSION_ID, SCHOOL_CLASS_ID AND SECTION_ID ARE DELIBERATELY DUPLICATED FROM THE
 * ENROLLMENT, NOT LEFT PURELY DERIVED. Every other module since Scores has stored only the
 * single foreign key that names an academic fact and reached everything else through it
 * (Score never stores class_id or student_id; Result never stores student_id). Attendance
 * departs from that default on purpose, for a reason those modules do not share: this
 * project's brief for this module names "class + date" and "session + date" as REQUIRED
 * indexes, because the module's own primary workflow is "load the whole class roster for one
 * date" - a class-scoped, high-frequency read this table needs to answer efficiently without
 * a join through enrollments on every request. The duplication is safe rather than a
 * cache-invalidation risk because enrollments.school_class_id/section_id/academic_session_id
 * are THEMSELVES immutable once created (see the enrollments migration: a placement is never
 * repointed, only superseded by a new row) - there is no update path anywhere in this project
 * that could let these copies drift out of step with the enrollment they were taken from.
 * They are set once, from the enrollment, when a mark is recorded, and never amended
 * afterwards - see Attendance's own $fillable and AttendanceService.
 *
 * `date` is a plain date, not a timestamp: attendance is a once-per-day fact, not a
 * point-in-time event, matching enrollments.enrollment_date's own precedent.
 *
 * `unique(enrollment_id, date)` is the database-level half of "at most one attendance mark per
 * placement per day" - the form request enforces the same rule with a friendly 422 first (for
 * a single create); the index is what makes it true under a race, and is also the backstop
 * bulk recording's own upsert-by-lookup relies on. See AttendanceService for why a single
 * create() REJECTS a duplicate while bulk recording UPDATES one in place - one clear,
 * documented behaviour per entry point, not two competing ones.
 *
 * `recorded_by` is nullable and nullOnDelete, matching results.submitted_by/promotions.
 * decided_by: the historical fact that someone took this register must outlive the specific
 * user account that took it.
 *
 * Every other foreign key is restrictOnDelete, matching every anchor-style reference
 * introduced since Module 05: attendance is exactly the kind of academic-history record that
 * must not be silently orphaned by deleting the enrollment, session, class or section it
 * names.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();

            // Copied from the enrollment at write time - see the class docblock for why these
            // three are duplicated rather than purely derived.
            $table->foreignId('academic_session_id')->constrained('academic_sessions')->restrictOnDelete();
            $table->foreignId('school_class_id')->constrained('classes')->restrictOnDelete();
            $table->foreignId('section_id')->constrained('sections')->restrictOnDelete();

            $table->date('date');
            $table->string('status', 20);

            // Free-form: why a student was marked absent, an excuse note, or any context
            // worth keeping with the mark - matching scores.remarks/enrollments.notes.
            $table->text('remarks')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The database-level guarantee behind "one mark per placement per day". See the
            // class docblock. Leftmost column also serves "every attendance mark for this
            // enrollment", the shape a student's own history read needs.
            $table->unique(['enrollment_id', 'date']);

            // The brief's own two named query shapes this module exists to serve fast: a
            // class register for one date, and a whole session's marks for one date (a
            // school-wide daily report). Neither is served efficiently by the unique index
            // above, whose leftmost column is enrollment_id, not class or session.
            $table->index(['school_class_id', 'section_id', 'date']);
            $table->index(['academic_session_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
