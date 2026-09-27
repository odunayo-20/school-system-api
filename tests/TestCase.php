<?php

namespace Tests;

use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every feature test starts from the five foundational roles and the Module 01
     * permissions, so authorization assertions are made against a known baseline.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
        ]);
    }
}
