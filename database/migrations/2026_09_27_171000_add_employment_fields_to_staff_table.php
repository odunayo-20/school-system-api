<?php

use App\Enums\EmploymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the employment fields Module 03 needs to the table Module 01 created.
     *
     * Additive only. The create_staff_table migration from Module 01 is left untouched so
     * the migration history stays truthful and no existing data is dropped; this adds
     * columns rather than editing the ones that are already there.
     *
     * Every column is nullable or has a default, so this applies cleanly to a populated
     * staff table. status defaults to ACTIVE because a staff row created before this
     * migration, or outside the API, is a working staff member by definition - there was no
     * status before, so absence of one must not mean inactive.
     */
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            // Indexed because the list endpoint filters on it, and an unindexed status
            // column makes every status-filtered page a table scan.
            $table->string('status')
                ->default(EmploymentStatus::ACTIVE->value)
                ->after('staff_type')
                ->index();

            // A record created before the date is known is legitimate, so this is nullable
            // rather than defaulted to today: a guessed date is worse than no date.
            $table->date('employment_date')->nullable()->after('staff_number');

            // users has no phone column at all, so this is new information rather than a
            // duplicate. A work number is not a login identity and may differ from it.
            $table->string('phone', 30)->nullable()->after('employment_date');

            // Free text, not a catalogue: see the Module 03 audit 2.6. Values like
            // "Principal" or "Accountant" are suggested in the docs and the seeder, and
            // nothing validates against a list.
            $table->string('designation', 100)->nullable()->after('phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn(['status', 'employment_date', 'phone', 'designation']);
        });
    }
};
