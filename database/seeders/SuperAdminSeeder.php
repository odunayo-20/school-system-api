<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Role as RoleModel;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Development convenience only.
 *
 * This seeder refuses to run outside the local and testing environments, so it can
 * never create a default administrator on a production database. The password is read
 * from the SUPER_ADMIN_PASSWORD environment variable and is generated - not
 * hardcoded - when that variable is absent.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('SuperAdminSeeder skipped: not a local or testing environment.');

            return;
        }

        $role = RoleModel::query()->where('name', Role::SUPER_ADMIN->value)->first();

        if (! $role) {
            $this->command?->error('SuperAdminSeeder skipped: run RoleSeeder first.');

            return;
        }

        $email = env('SUPER_ADMIN_EMAIL', 'superadmin@example.test');

        $user = User::query()->firstOrNew(['email' => $email]);
        $plainPassword = null;

        $user->forceFill([
            'name' => 'Super Admin',
            'role_id' => $role->getKey(),
            'status' => UserStatus::ACTIVE,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);

        if (! $user->exists) {
            $plainPassword = env('SUPER_ADMIN_PASSWORD') ?: Str::password(16);
            $user->password = $plainPassword;
        }

        $user->save();

        $this->command?->info($plainPassword === null
            ? "Development super admin ready: {$email}"
            : "Development super admin created: {$email} (password: {$plainPassword})");
    }
}
