<?php

namespace Database\Seeders;

use App\Enums\SchoolStatus;
use App\Models\School;
use Illuminate\Database\Seeder;

/**
 * The single school profile.
 *
 * The values come from config/school.php where a school can set them, and fall back to
 * sensible development defaults so a fresh clone is usable immediately.
 *
 * The record is located by "the one that already exists" rather than by name, because name
 * is a value this seeder WRITES and is therefore not a safe lookup key. Keying on it would
 * mean that changing SCHOOL_NAME in .env and re-seeding tried to insert a second row, which
 * the singleton_key index rejects: the operator would see a failed seed for what is really
 * a rename. Reading the existing row and writing over it makes re-seeding a rename, which is
 * what a change to that variable is meant to be.
 */
class SchoolSeeder extends Seeder
{
    public function run(): void
    {
        $school = School::current() ?? new School;

        $school->fill([
            'name' => $this->text('school.name'),
            'short_name' => $this->text('school.short_name'),
            'motto' => $this->text('school.motto'),
            'email' => $this->text('school.email'),
            'phone' => $this->text('school.phone'),
            'website' => $this->text('school.website'),
            'address_line1' => $this->text('school.address_line1'),
            'city' => $this->text('school.city'),
            'state' => $this->text('school.state'),
            'country' => $this->text('school.country'),
            'principal_name' => $this->text('school.principal_name'),
            'registration_number' => $this->text('school.registration_number'),
            'status' => SchoolStatus::ACTIVE,
        ]);

        $school->save();
    }

    /**
     * A configured string, trimmed, with "absent" preserved as null.
     *
     * API input arrives already trimmed by the TrimStrings middleware, but this seeder
     * writes config values straight to the model and bypasses the middleware entirely. A
     * stray space after a value in .env would otherwise be stored as part of the school's
     * name, and would show up in a printed report header. A value that is absent stays null
     * rather than becoming an empty string, which would read back as a value the school has.
     */
    protected function text(string $key): ?string
    {
        $value = config($key);

        if (is_null($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
