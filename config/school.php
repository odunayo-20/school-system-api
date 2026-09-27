<?php

/**
 * Seed values for the single school this installation manages.
 *
 * The school is configuration rather than a tenant, so its identity belongs in config
 * alongside the other environment-driven values, not hardcoded in a seeder. Everything
 * here is nullable: an installation that has not filled in a value simply leaves it null
 * and fills it in later through PUT /api/v1/school.
 */
return [

    'name' => env('SCHOOL_NAME', 'Greenfield International School'),

    'short_name' => env('SCHOOL_SHORT_NAME', 'GIS'),

    'motto' => env('SCHOOL_MOTTO'),

    'email' => env('SCHOOL_EMAIL'),

    'phone' => env('SCHOOL_PHONE'),

    'alternate_phone' => env('SCHOOL_ALTERNATE_PHONE'),

    'website' => env('SCHOOL_WEBSITE'),

    'address_line1' => env('SCHOOL_ADDRESS_LINE1'),

    'city' => env('SCHOOL_CITY'),

    'state' => env('SCHOOL_STATE'),

    'country' => env('SCHOOL_COUNTRY'),

    'principal_name' => env('SCHOOL_PRINCIPAL_NAME'),

    'registration_number' => env('SCHOOL_REGISTRATION_NUMBER'),

    /*
    |--------------------------------------------------------------------------
    | Academic Session Start Month
    |--------------------------------------------------------------------------
    |
    | The month the school year begins, which decides how the seeded current session is
    | dated. A school whose year starts in January sets this to 1; one that starts in
    | September leaves it at 9. Months are 1-12. The seeded session spans one year from
    | this month and is split into three terms.
    |
    */

    'academic_session_start_month' => (int) env('SCHOOL_ACADEMIC_SESSION_START_MONTH', 9),

];
