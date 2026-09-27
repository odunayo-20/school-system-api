<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    /** @use HasFactory<\Database\Factories\PermissionFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'label',
        'description',
    ];

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * The module a permission belongs to, derived from the permission name so it
     * can never drift out of sync. "users.view" belongs to the "users" group.
     */
    public function group(): string
    {
        return explode('.', $this->name)[0];
    }

    /**
     * A permission name is "<module>.<action>" in lower snake case, e.g.
     * "students.view" or "results.publish". The shape is validated without touching
     * the database so the Gate can safely distinguish a permission ability from a
     * Laravel policy ability (which is always a bare verb).
     */
    public static function isValidName(string $name): bool
    {
        return preg_match('/^[a-z][a-z0-9_-]*\.[a-z][a-z0-9_.-]*$/', $name) === 1;
    }
}
