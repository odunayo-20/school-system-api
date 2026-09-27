<?php

namespace Tests;

use Database\Seeders\AcademicPermissionSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every feature test starts from the five foundational roles and the permissions of
     * every module implemented so far, so authorization assertions are made against a
     * known baseline.
     *
     * Both permission seeders run, in the same order as DatabaseSeeder. They are additive
     * and independent: PermissionSeeder grants Module 01's permissions with sync() and
     * AcademicPermissionSeeder grants Module 02's with syncWithoutDetaching(), so running
     * the second cannot revoke the first. That is exactly the property a test of the
     * combined baseline depends on.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            AcademicPermissionSeeder::class,
        ]);
    }
}
