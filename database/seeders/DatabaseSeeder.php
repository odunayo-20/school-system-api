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
     *
     * StudentSeeder runs after AcademicStructureSeeder only for readability - it does not
     * read any academic table, because a pupil's placement is an enrollment fact and this
     * module deliberately has no column to put it in. The order that actually matters is the
     * permission seeders, above.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            // The order of the three permission seeders is load-bearing. Module 01's
            // PermissionSeeder grants with sync(), which REPLACES each role's permission
            // set, so any later seeder that used sync() would revoke it. The two module
            // seeders that follow grant with syncWithoutDetaching() and therefore add to
            // whatever is already there. Reversing the order would leave the tables looking
            // correct while every Module 02 and Module 03 route answered 403.
            PermissionSeeder::class,
            AcademicPermissionSeeder::class,
            StaffPermissionSeeder::class,
            StudentPermissionSeeder::class,
            AdmissionPermissionSeeder::class,
            SchoolSeeder::class,
            AcademicStructureSeeder::class,
            AcademicCalendarSeeder::class,
            StaffSeeder::class,
            StudentSeeder::class,
            SuperAdminSeeder::class,
        ]);
    }
}
