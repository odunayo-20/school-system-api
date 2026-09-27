<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A term inside an academic session, e.g. "Second Term" of 2026/2027.
 *
 * term_number is a number, not a string. "Second Term" is presentation only; ordering,
 * comparison and the "only one active term" rule all key on the integer so they never
 * depend on parsing an English label.
 *
 * The foreign key uses restrictOnDelete: deleting a session that still has terms would
 * orphan the academic calendar, so the database refuses and the service turns that refusal
 * into a 422 with a useful message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_session_id')->constrained('academic_sessions')->restrictOnDelete();
            $table->string('name', 100);
            $table->unsignedTinyInteger('term_number');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('UPCOMING')->index();
            $table->boolean('active_marker')->nullable()->unique();
            $table->timestamps();

            // A session may only hold each term number once: 2026/2027 + 1, + 2, + 3.
            $table->unique(['academic_session_id', 'term_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('terms');
    }
};
