<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An educational stage: Nursery, Primary, Junior Secondary, Senior Secondary.
 *
 * The values are DATA, not code. Nothing in the application hardcodes these four; a
 * school that adds a Pre-School stage inserts a row and it is immediately usable.
 * sort_order is what gives the structure a meaningful order in the interface, since a
 * class level is an ordered progression rather than an unordered set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_levels', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('code', 20)->unique();
            $table->smallInteger('sort_order')->default(0)->index();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('class_levels');
    }
};
