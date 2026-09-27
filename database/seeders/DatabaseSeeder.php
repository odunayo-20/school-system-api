<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Laravel's generated DatabaseSeeder disables model events for the whole seeding
     * run via the WithoutModelEvents trait. That trait is deliberately NOT used here.
     *
     * Seeders are ordinary writers, and the academic domain derives data from other
     * columns inside the model: the schools, academic_sessions and terms tables each
     * carry a nullable active_marker column maintained by a model `saving` hook. Because
     * Seeder::__invoke() checks the trait on the seeder being invoked and DatabaseSeeder
     * calls every other seeder from inside its own run(), that trait suppresses model
     * events for the ENTIRE nested seeding run. Seeded ACTIVE sessions, terms and the
     * school profile would therefore be written with active_marker = NULL, and the unique
     * index that makes "at most one current record" a database fact would silently not
     * apply to seeded data.
     *
     * Nothing else in this project observes model events, so suppressing them buys no
     * performance and only risks that class of drift. Model events are therefore left
     * enabled, which keeps the invariant in the model where it cannot be forgotten.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            AcademicPermissionSeeder::class,
            SchoolSeeder::class,
            AcademicStructureSeeder::class,
            AcademicCalendarSeeder::class,
            SuperAdminSeeder::class,
        ]);
    }
}
