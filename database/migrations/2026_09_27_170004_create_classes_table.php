<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A class within a class level: "Primary 1" belongs to the "Primary" level.
 *
 * A class is NOT a class level, and it is NOT a section. Primary 5 is a class; Primary 5A
 * is a section of that class, held in the sections table.
 *
 * name and code are unique *within the class level* rather than globally, because a class
 * is only ever identified inside the level that owns it. Scoping the unique key to the
 * parent also means the school can run two parallel streams of one level without a
 * collision, and keeps the constraint aligned with the foreign key.
 *
 * There is deliberately no student, subject or teacher column here. Those are later
 * modules, and attaching a person to a class is an enrollment decision, not a property of
 * the class itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_level_id')->constrained('class_levels')->restrictOnDelete();
            $table->string('name', 100);
            $table->string('code', 20);
            $table->smallInteger('sort_order')->default(0)->index();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();

            $table->unique(['class_level_id', 'name']);
            $table->unique(['class_level_id', 'code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('classes');
    }
};
