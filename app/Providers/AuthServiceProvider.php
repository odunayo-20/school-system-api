<?php

namespace App\Providers;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Single place where role and permission authorization is resolved.
 *
 * Controllers, policies and middleware must never compare roles inline. They ask the
 * Gate, and the Gate asks the User model.
 */
class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register any authentication / authorization services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerRoleAbility();
        $this->registerSuperAdminBypass();
        $this->registerPermissionAbilities();
    }

    /**
     * Role checks go through the Gate as well, so that the centralised super-admin
     * bypass below applies to the "role" middleware and to policies.
     */
    protected function registerRoleAbility(): void
    {
        Gate::define(
            'role',
            fn (User $user, string $role): bool => $user->hasRole($role)
        );
    }

    /**
     * Super Admin is the highest privileged role. The bypass is centralised here so
     * that no controller ever needs an "if super admin, skip the check" branch, and
     * so that policy classes inherit the behaviour for free.
     */
    protected function registerSuperAdminBypass(): void
    {
        Gate::before(function (User $user): ?bool {
            return $user->isSuperAdmin() ? true : null;
        });
    }

    /**
     * Permission abilities are dot-namespaced, e.g. "students.view" or
     * "users.update". Laravel policy abilities are bare verbs ("view", "create",
     * "update", "delete"), so a dot unambiguously identifies a permission.
     *
     * Resolving permissions from the database here — rather than registering one
     * Gate::define() per row — means a later module can seed its own permissions and
     * have them enforced immediately, with no code change and no cached gate list.
     */
    protected function registerPermissionAbilities(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            if (! $this->isPermissionAbility($ability)) {
                return null;
            }

            return $user->hasPermission($ability) ? true : null;
        });
    }

    protected function isPermissionAbility(string $ability): bool
    {
        return str_contains($ability, '.') && Permission::isValidName($ability);
    }
}
