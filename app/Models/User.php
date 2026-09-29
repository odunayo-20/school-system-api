<?php

namespace App\Models;

use App\Enums\Role as RoleEnum;
use App\Enums\StaffType;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    /**
     * Normalise the email address so lookups are case-insensitive regardless of the
     * database collation.
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => mb_strtolower(trim($value)),
        );
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return HasOne<Staff, $this>
     */
    public function staff(): HasOne
    {
        return $this->hasOne(Staff::class);
    }

    /**
     * The pupil record behind this login, if this account is a pupil's portal account.
     *
     * Module 04 creates the relationship and the nullable column behind it, but exposes no
     * way to populate either: a pupil does not need a login to exist, so the link is
     * reserved for the portal module rather than being offered here as a field nobody can
     * set. See the students migration.
     *
     * @return HasOne<Student, $this>
     */
    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    /**
     * Permissions granted directly to this user, in addition to those inherited
     * from their role. This is what allows two STAFF users to hold different
     * permissions without inventing extra authentication roles.
     *
     * @return BelongsToMany<Permission, $this>
     */
    public function directPermissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::ACTIVE;
    }

    public function isSuspended(): bool
    {
        return $this->status === UserStatus::SUSPENDED;
    }

    public function roleEnum(): ?RoleEnum
    {
        return $this->role?->enum();
    }

    public function isSuperAdmin(): bool
    {
        return $this->roleEnum()?->isSuperAdmin() ?? false;
    }

    public function isStaff(): bool
    {
        return $this->roleEnum() === RoleEnum::STAFF;
    }

    public function staffType(): ?StaffType
    {
        return $this->isStaff() ? $this->staff?->staff_type : null;
    }

    public function hasRole(RoleEnum|string ...$roles): bool
    {
        $current = $this->roleEnum();

        if ($current === null) {
            return false;
        }

        foreach ($roles as $role) {
            if ($current === ($role instanceof RoleEnum ? $role : RoleEnum::tryFrom($role))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $permissions
     */
    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    public function hasPermission(string $permission): bool
    {
        $resolved = $this->resolvePermissions();

        return isset($resolved[$permission]) || $this->isSuperAdmin();
    }

    /**
     * Every permission name this user may exercise, keyed by name.
     *
     * @return Collection<string, Permission>
     */
    public function permissions(): Collection
    {
        return $this->resolvePermissions();
    }

    /**
     * The names of every permission this user may exercise.
     *
     * @return list<string>
     */
    public function permissionNames(): array
    {
        return $this->resolvePermissions()->keys()->all();
    }

    /**
     * Record a successful authentication. Tokens belonging to accounts that are no
     * longer active are revoked so a suspension takes effect immediately.
     */
    public function recordLogin(): void
    {
        $this->forceFill(['last_login_at' => now()])->save();
    }

    /**
     * @return Collection<string, Permission>
     */
    protected function resolvePermissions(): Collection
    {
        $rolePermissions = $this->role
            ? $this->role->permissions->keyBy('name')
            : new Collection;

        // union() preserves the string keys; merge() would renumber them.
        return $rolePermissions->union($this->directPermissions->keyBy('name'));
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', UserStatus::ACTIVE->value);
    }
}
