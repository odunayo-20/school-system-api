<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The reusable academic subject catalogue: Mathematics, English Language, Biology.
 *
 * Structurally a twin of class_levels, and deliberately so: both are a flat, globally
 * unique, ordered catalogue retired with the same CatalogStatus rather than deleted the
 * moment a school stops offering them. name and code are unique across the WHOLE school,
 * not scoped to anything - unlike sections, a subject has no natural parent to scope
 * uniqueness to. Mathematics is Mathematics regardless of which class teaches it.
 *
 * THIS TABLE DELIBERATELY HOLDS NO class_id, class_level_id, teacher_id, staff_id,
 * assessment or score column. A subject definition is reusable across every class that
 * offers it; which classes actually offer it is a separate fact, recorded once per
 * offering in class_subjects. Putting a class or teacher column here would collapse the
 * catalogue into one specific offering and force a duplicate "Mathematics" row for every
 * class that teaches it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $table) {
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
        Schema::dropIfExists('subjects');
    }
};
