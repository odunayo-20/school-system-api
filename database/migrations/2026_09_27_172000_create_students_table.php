<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A pupil's persistent identity: who this person is, and whether they are on the roll.
 *
 * WHAT THIS TABLE DELIBERATELY DOES NOT CONTAIN
 *
 * There is no current_class_id, no current_section_id and no current_session_id. Those would
 * be a denormalised copy of the newest row in a future enrollments table, and a copy is only
 * as correct as the code that maintains it. The first promotion that wrote the column but
 * failed to write the enrollment row would leave the roll showing a pupil in a class they had
 * left, with nothing in the database to say so. The placement of a pupil in a class is a
 * decision about a specific academic session, and it belongs in a table that can hold a
 * history of those decisions:
 *
 *     2024/2025 -> JSS 2 -> A
 *     2025/2026 -> JSS 3 -> A
 *     2026/2027 -> SS 1  -> B
 *
 * Those three rows are one pupil, and the pupil row is written once and never rewritten by
 * any of them. See the Module 04 audit, decision 3.
 *
 * There is no admission_number either. An admission number identifies one admission
 * *attempt*, and a pupil can have more than one - they may apply, be refused, and apply again,
 * or transfer in and be admitted a second time. The pupil's own number is not that, and
 * admission numbering belongs to the admission module.
 *
 * user_id is NULLABLE, and that is the one place this table deliberately differs from the
 * staff table beside it.
 *
 * A staff record cannot exist without its login, because Module 01 defined staff.user_id as
 * NOT NULL - a constraint that predates Module 03 and which Module 03 inherited rather than
 * chose. No such constraint exists for pupils, and requiring one would be wrong: a Nursery
 * entrant has no email address, a child's login should not be a precondition of recording
 * that they exist, and the lifecycle runs Student -> Admission -> Enrollment, so identity
 * comes before anything that might want a portal account.
 *
 * The delete behaviour is therefore the opposite of staff.user_id's CASCADE. A pupil is a
 * person and outlives their portal login; deleting the login must not delete the child. That
 * is why this is nullOnDelete and not cascadeOnDelete.
 *
 * The column is created and the relationship exists, but no Module 04 payload can populate
 * it: a link that grants nothing yet is not a feature. The portal module owns provisioning.
 *
 * The name is split into first / middle / last and lives HERE, not on users, because - unlike
 * staff - a pupil does not have to have an account to have a name. Module 03 could put the
 * name on the account because the account was mandatory; here it is not, so the identity
 * fields are the pupil's own. A linked account's users.name is a login-facing label and is
 * not the pupil's identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();

            // The reserved portal link. Nullable because a pupil may have no account;
            // unique because one login is at most one pupil. nullOnDelete so removing a
            // login leaves the person behind.
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();

            // The pupil's own number, derived from this table's own primary key when a
            // client does not supply one. Nullable and unique, exactly like
            // staff.staff_number: a primary key cannot be read twice, so two concurrent
            // creates cannot derive the same number, whereas count() + 1 can.
            //
            // "Nullable" is about the SCHEMA, not about how this module uses it. A pupil
            // added without a number always ends up with one; the nullability is there so a
            // future numbering scheme can leave the column empty. It is NOT a licence to
            // insert null and fill it in afterwards: under SQL Server a unique index treats
            // NULL as a single value and permits only one null row per column, so that
            // approach would work on SQLite, MySQL and PostgreSQL - the three the tests run -
            // and fail on the fourth the project configures. StudentService reserves a
            // per-insert UUID for that window instead. See reservationNumber() there.
            $table->string('student_number')->nullable()->unique();

            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();

            // Nullable, not required: a pupil with a single name is a real case, and
            // inventing a surname to fill a NOT NULL column would put invented data on a
            // permanent record.
            $table->string('last_name', 100)->nullable();

            // Null is "not recorded", which is different from a wrong date.
            $table->date('date_of_birth')->nullable();

            $table->string('gender', 20)->nullable();

            $table->string('status', 20)->default('ACTIVE')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
