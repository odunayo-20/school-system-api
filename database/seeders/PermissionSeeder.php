<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Permission;
use App\Models\Role as RoleModel;
use Illuminate\Database\Seeder;

/**
 * Module 01 seeds only the permissions that belong to the account and role domain.
 * Business permissions (students.*, admissions.*, results.*, ...) are deliberately
 * left to the module that owns them, and will be picked up by the Gate automatically
 * the moment they are seeded — no code change required.
 */
class PermissionSeeder extends Seeder
{
    /**
     * @var array<string, array{label: string, description: string}>
     */
    protected array $permissions = [
        'profile.view' => [
            'label' => 'View own profile',
            'description' => 'Read the authenticated user\'s own account details.',
        ],
        'profile.update' => [
            'label' => 'Update own profile',
            'description' => 'Change the authenticated user\'s own account details.',
        ],
        'users.view' => [
            'label' => 'View user accounts',
            'description' => 'List and read user accounts.',
        ],
        'users.create' => [
            'label' => 'Create user accounts',
            'description' => 'Create user accounts and staff records.',
        ],
        'users.update' => [
            'label' => 'Update user accounts',
            'description' => 'Update user accounts, including role and status.',
        ],
        'users.delete' => [
            'label' => 'Delete user accounts',
            'description' => 'Delete user accounts.',
        ],
        'roles.manage' => [
            'label' => 'Manage roles and permissions',
            'description' => 'Assign roles and grant permissions.',
        ],
    ];

    /**
     * @var array<string, list<string>>
     */
    protected array $rolePermissions = [
        Role::SUPER_ADMIN->value => [
            'profile.view', 'profile.update',
            'users.view', 'users.create', 'users.update', 'users.delete',
            'roles.manage',
        ],
        Role::ADMIN->value => [
            'profile.view', 'profile.update',
            'users.view', 'users.create', 'users.update',
        ],
        Role::REGISTRAR->value => [
            'profile.view', 'profile.update',
            'users.view',
        ],
        Role::STAFF->value => [
            'profile.view', 'profile.update',
        ],
        Role::STUDENT->value => [
            'profile.view', 'profile.update',
        ],
    ];

    public function run(): void
    {
        $permissions = [];

        foreach ($this->permissions as $name => $attributes) {
            $permissions[$name] = Permission::query()->updateOrCreate(['name' => $name], $attributes);
        }

        foreach ($this->rolePermissions as $roleName => $names) {
            $role = RoleModel::query()->where('name', $roleName)->first();

            if (! $role) {
                continue;
            }

            $role->permissions()->sync(
                collect($names)->map(fn (string $name): int => $permissions[$name]->getKey())->all()
            );
        }
    }
}
