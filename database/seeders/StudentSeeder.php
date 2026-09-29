<?php

namespace Database\Seeders;

use App\Enums\Gender;
use App\Enums\StudentStatus;
use App\Models\Student;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Two development pupil records, so a fresh install has something to look at.
 *
 * Development convenience only, like SuperAdminSeeder and StaffSeeder: this seeder refuses to
 * run outside the local and testing environments, so it can never create a pupil record on a
 * production database. It seeds exactly two, with deterministic names so a re-seed updates
 * rather than duplicates. It exists to prove both branches of the lifecycle and the name
 * parts work, and to give the list endpoint a page with rows in it - not to be a realistic
 * school roll.
 *
 * NO LOGIN ACCOUNTS ARE CREATED, and that is the point of the module rather than an
 * omission. Pupils are people, and in this system a pupil record does not require a login:
 * a Nursery entrant has no email address, and the lifecycle runs Student -> Admission ->
 * Enrollment, so identity comes before anything that would want a portal account. Seeding two
 * pupil accounts here would also mean inventing two email addresses for children.
 *
 * The names are plainly fictional and the dates of birth are fixed, so no real child's
 * details can end up in a development database.
 *
 * The student numbers are derived exactly the way the API derives them, from the row's own
 * primary key, so a seeded number and an API-created number are indistinguishable.
 */
class StudentSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('StudentSeeder skipped: not a local or testing environment.');

            return;
        }

        $records = [
            [
                'first_name' => 'Ada',
                'middle_name' => 'Ngozi',
                'last_name' => 'Okonkwo',
                'gender' => Gender::FEMALE,
                // A fixed date rather than a random one, so a re-seed cannot change it and
                // the record stays reproducible.
                'date_of_birth' => '2015-04-02',
            ],
            [
                'first_name' => 'Bello',
                'middle_name' => null,
                'last_name' => 'Sani',
                'gender' => Gender::MALE,
                // No middle name, on purpose: it exercises the nullable name part and shows
                // a roll rendering without a doubled space.
                'date_of_birth' => '2018-11-30',
            ],
        ];

        foreach ($records as $record) {
            $student = Student::query()->firstOrNew([
                'first_name' => $record['first_name'],
                'last_name' => $record['last_name'],
            ]);

            $student->fill([
                'middle_name' => $record['middle_name'],
                'gender' => $record['gender'],
                'date_of_birth' => $record['date_of_birth'],
                'status' => StudentStatus::ACTIVE,
            ]);

            // Derived, not counted. count() + 1 races: two simultaneous creates read the
            // same count, derive the same number, and the loser's insert dies on the unique
            // index as a 500. A primary key cannot be read twice.
            $student->save();

            if (is_null($student->student_number)) {
                $student->forceFill([
                    'student_number' => 'STU-'.Str::padLeft((string) $student->getKey(), 4, '0'),
                ])->save();
            }
        }

        $this->command?->info(sprintf(
            'Development pupils ready: %d records, no login accounts.',
            Student::query()->count(),
        ));
    }
}
