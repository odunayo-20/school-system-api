<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reusable assessment category catalogue: CA, Test, Examination, Project.
 *
 * Structurally a twin of subjects and class_levels, and deliberately so: a flat, globally
 * unique, ordered catalogue retired with the same CatalogStatus rather than deleted. "CA" is
 * "CA" regardless of which class subject or term it is later configured against.
 *
 * THIS TABLE DELIBERATELY HOLDS NO class_subject_id, term_id, max_score or weight column. A
 * category definition is reusable across every configured assessment that uses it; which
 * class subject and term an actual assessment belongs to, and its own score/weight, is a
 * separate fact recorded once per configured assessment in the assessments table below - the
 * identical split subjects/class_subjects already establishes one level up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('code', 20)->unique();
            $table->smallInteger('sort_order')->default(0)->index();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_types');
    }
};
