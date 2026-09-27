<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A school year, e.g. "2026/2027".
 *
 * Sessions are never deleted casually: enrollment history, results, promotion and report
 * cards will all reference them, so a session is retired by setting status to COMPLETED
 * and the rows remain readable.
 *
 * active_marker mirrors the singleton technique used by schools and terms: 1 on the
 * single ACTIVE session, NULL on every other, with a plain UNIQUE index. See the Module 02
 * audit, section D.4.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('UPCOMING')->index();
            $table->boolean('active_marker')->nullable()->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('academic_sessions');
    }
};
