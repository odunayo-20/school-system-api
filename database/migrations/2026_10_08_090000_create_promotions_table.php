<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One decision about what happens to a student's placement going into a NEW academic session:
 * "JSS 2 A, 2025/2026 -> PROMOTED -> JSS 3 A, 2026/2027".
 *
 * A GENUINELY NEW TABLE, not a reuse of `enrollments`. `Enrollment` cannot represent a
 * GRADUATED or NOT_ELIGIBLE decision at all - both, by definition, produce no new placement -
 * so there is no enrollment row for either to attach a "decision" to. This table is the one
 * place a decision with no resulting enrollment still gets a permanent historical record. See
 * the Module 15 audit §2 for why an enrollment-only design was considered and rejected.
 *
 * THE OLD ENROLLMENT IS NEVER TOUCHED BY THIS TABLE. `source_enrollment_id` only ever POINTS
 * at it; nothing here, and nothing in PromotionService, ever mutates its status, class or
 * section. `EnrollmentStatus`'s own docblock (Module 06) already anticipated this exactly:
 * "Promotion (a future module) creates a NEW row for the new session; it never mutates an
 * existing one." ACTIVE on a three-year-old enrollment already means exactly what it needs to
 * mean here - "this placement was never withdrawn or cancelled" - so no new enrollment status
 * (no PROMOTED, no COMPLETED) was added to represent "this placement was promoted from." That
 * fact lives here instead, as a row referencing the enrollment, not as a mutation of it.
 *
 * `target_academic_session_id` is its own column, not read through `target_enrollment_id`,
 * because a GRADUATED or NOT_ELIGIBLE decision has no target enrollment to read it from - this
 * is the one fact every decision type needs that only this table can hold.
 * `target_enrollment_id` is nullable for exactly those two decisions.
 *
 * `unique(source_enrollment_id, target_academic_session_id)` is the database-level half of
 * "at most one promotion decision per placement, per target session" - the same idempotency
 * guarantee the brief's own §14 asks for. PromotionService itself never attempts a second
 * insert for a pair that already has one (see its own docblock); this index is the race-safe
 * backstop for two concurrent requests, the identical two-layer technique every prior module's
 * own unique index already uses.
 *
 * Every foreign key is `restrictOnDelete`, matching every foreign key introduced since Module
 * 05: a promotion is exactly the kind of academic-history anchor that must not be silently
 * orphaned by deleting the enrollment or session it names. `decided_by` is the sole exception
 * (`nullOnDelete`), matching Module 13's own `submitted_by`/`approved_by`/etc.: the historical
 * fact that someone decided this must outlive the specific user account that decided it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('source_enrollment_id')->constrained('enrollments')->restrictOnDelete();
            $table->foreignId('target_academic_session_id')->constrained('academic_sessions')->restrictOnDelete();

            // Null for GRADUATED and NOT_ELIGIBLE - see the class docblock.
            $table->foreignId('target_enrollment_id')->nullable()->constrained('enrollments')->restrictOnDelete();

            $table->string('decision', 20);

            // Free-form, optional administrative note - matching enrollments.notes and
            // admissions.notes own precedent. Never mandatory: nothing in this project's
            // existing requirements makes a reason a required field on any decision.
            $table->text('reason')->nullable();

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at');

            $table->timestamps();

            // The database-level guarantee behind "one promotion decision per placement, per
            // target session". See the class docblock.
            $table->unique(['source_enrollment_id', 'target_academic_session_id']);

            // The one list query this module's own history endpoint needs answered: "every
            // promotion decision recorded for this target session" - an administrator
            // reviewing how a whole cohort moved into the new year.
            $table->index('target_academic_session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
