<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The single school this installation manages.
 *
 * This is a singleton CONFIGURATION record, not a tenant. No other table receives a
 * school_id: the system describes one school, so a foreign key to "the school" would be a
 * constant column that only ever holds 1, and would make the schema look like a
 * multi-school system it is not. See the Module 02 audit, finding C2.
 *
 * singleton_key is what makes "there is exactly one school row" a DATABASE fact rather
 * than an application convention. It is NOT NULL with a constant default of 1 and a UNIQUE
 * index, so a second school row is rejected by every supported driver (SQLite, MySQL,
 * PostgreSQL, SQL Server) with no driver-specific SQL and no partial index.
 *
 * The nullable active_marker used on academic_sessions and terms is the wrong tool here,
 * and deliberately not copied. A nullable unique column permits any number of rows whose
 * marker is NULL, so it would enforce "at most one ACTIVE school" while still allowing a
 * hundred INACTIVE ones. Multiple non-active sessions and terms are real and expected;
 * multiple schools are not.
 *
 * status is advisory in this module. Nothing in Module 02 refuses work based on it, and it
 * is not a switch that de-configures the installation: School::current() returns the one
 * row whatever its status, so marking the school INACTIVE cannot make the profile
 * disappear and cannot strand the academic calendar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('short_name', 50);
            $table->string('motto')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('alternate_phone', 30)->nullable();
            $table->string('website')->nullable();
            $table->string('address_line1')->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 120)->nullable();
            $table->string('country', 120)->nullable();
            $table->string('principal_name', 150)->nullable();
            $table->string('registration_number', 100)->nullable();
            $table->string('status', 20)->default('ACTIVE')->index();
            $table->boolean('singleton_key')->default(true);
            $table->timestamps();

            $table->unique('singleton_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schools');
    }
};
