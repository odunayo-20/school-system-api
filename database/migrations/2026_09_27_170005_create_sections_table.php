<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A section (arm) inside a class: Primary 5 has A, B and C.
 *
 * Sections are CLASS-SPECIFIC, not globally reusable. Section "A" in Primary 5 and
 * section "A" in JSS 1 are unrelated rows that happen to share a name, so uniqueness is
 * scoped to the parent class and is deliberately NOT global. A model where a section were
 * a shared entity joined through a pivot would imply an identity that means nothing to a
 * school, and would double the queries every later module needs. See the Module 02 audit,
 * section D.4.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_class_id')->constrained('classes')->restrictOnDelete();
            $table->string('name', 50);
            $table->string('code', 20);
            $table->smallInteger('sort_order')->default(0)->index();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();

            $table->unique(['school_class_id', 'name']);
            $table->unique(['school_class_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sections');
    }
};
