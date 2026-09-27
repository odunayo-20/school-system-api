<?php

namespace Database\Seeders;

use App\Enums\EmploymentStatus;
use App\Enums\Role;
use App\Enums\StaffType;
use App\Enums\UserStatus;
use App\Models\Role as RoleModel;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Two development staff records, so a fresh install has something to look at.
 *
 * Development convenience only, like SuperAdminSeeder: this seeder refuses to run outside
 * the local and testing environments, so it can never create a login account on a
 * production database. It seeds exactly one of each staff type, with a deterministic
 * address so a re-seed updates rather than duplicates. This is not a set of realistic
 * school staff and it is not a fixture of twenty teachers - it exists to prove both
 * branches of staff_type work and to give the list endpoint a page with rows in it.
 *
 * The emails are on the .test reserved domain (RFC 2606) so a seeded record can never
 * collide with a real mailbox, and the password is one a developer can type while testing.
 * The accounts are marked verified because an administrator provisioned them, exactly as
 * StaffService::create() does.
 */
class StaffSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('StaffSeeder skipped: not a local or testing environment.');

            return;
        }

        $role = RoleModel::query()->where('name', Role::STAFF->value)->first();

        if (! $role) {
            $this->command?->error('StaffSeeder skipped: run RoleSeeder first.');

            return;
        }

        $password = env('SEED_STAFF_PASSWORD', 'Password!123');

        $records = [
            [
                'email' => 'teacher@example.test',
                'name' => 'Amina Yusuf',
                'staff_type' => StaffType::TEACHING,
                'designation' => 'Teacher',
                'employment_date' => now()->subYears(2)->startOfYear()->toDateString(),
            ],
            [
                'email' => 'clerk@example.test',
                'name' => 'Daniel Okoro',
                'staff_type' => StaffType::NON_TEACHING,
                'designation' => 'Registrar Clerk',
                'employment_date' => now()->subYear()->startOfYear()->toDateString(),
            ],
        ];

        foreach ($records as $record) {
            $user = User::query()->firstOrNew(['email' => $record['email']]);

            $user->forceFill([
                'name' => $record['name'],
                'role_id' => $role->getKey(),
                'status' => UserStatus::ACTIVE,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]);

            if (! $user->exists) {
                $user->password = $password;
            }

            $user->save();

            // Derived the same way the API derives it, from the account's own id, so a
            // seeded number and an API-created number are indistinguishable.
            Staff::query()->updateOrCreate(
                ['user_id' => $user->getKey()],
                [
                    'staff_type' => $record['staff_type'],
                    'staff_number' => 'STAFF-'.Str::padLeft((string) $user->getKey(), 4, '0'),
                    'status' => EmploymentStatus::ACTIVE,
                    'employment_date' => $record['employment_date'],
                    'designation' => $record['designation'],
                    'phone' => null,
                ],
            );
        }
    }
}
