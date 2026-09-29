<?php

namespace Tests;

use Database\Seeders\AcademicPermissionSeeder;
use Database\Seeders\AdmissionPermissionSeeder;
use Database\Seeders\AssessmentPermissionSeeder;
use Database\Seeders\EnrollmentPermissionSeeder;
use Database\Seeders\GradingPermissionSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\ResultPermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ScorePermissionSeeder;
use Database\Seeders\StaffPermissionSeeder;
use Database\Seeders\StudentPermissionSeeder;
use Database\Seeders\SubjectPermissionSeeder;
use Database\Seeders\TeacherAssignmentPermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every feature test starts from the five foundational roles and the permissions of
     * every module implemented so far, so authorization assertions are made against a
     * known baseline.
     *
     * All twelve permission seeders run, in the same order as DatabaseSeeder, and that
     * order is load-bearing. PermissionSeeder grants Module 01's permissions with sync(),
     * which REPLACES each role's permission set; AcademicPermissionSeeder,
     * StaffPermissionSeeder, StudentPermissionSeeder, AdmissionPermissionSeeder,
     * EnrollmentPermissionSeeder, SubjectPermissionSeeder, TeacherAssignmentPermissionSeeder,
     * AssessmentPermissionSeeder, ScorePermissionSeeder, GradingPermissionSeeder and
     * ResultPermissionSeeder grant theirs with syncWithoutDetaching(), so running them
     * afterwards adds to the set rather than replacing it. Seeded in the other order, the
     * database would look correct and every Module 02 through Module 12 route would answer
     * 403. That a test of the combined baseline depends on this is the point of seeding all
     * twelve here rather than only the ones the test under way needs.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            AcademicPermissionSeeder::class,
            StaffPermissionSeeder::class,
            StudentPermissionSeeder::class,
            AdmissionPermissionSeeder::class,
            EnrollmentPermissionSeeder::class,
            SubjectPermissionSeeder::class,
            TeacherAssignmentPermissionSeeder::class,
            AssessmentPermissionSeeder::class,
            ScorePermissionSeeder::class,
            GradingPermissionSeeder::class,
            ResultPermissionSeeder::class,
        ]);
    }
}
