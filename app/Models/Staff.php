<?php

namespace App\Models;

use App\Enums\StaffType;
use Database\Factories\StaffFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Classification record for a user whose role is STAFF. Teaching and non-teaching
 * staff are staff types, not authentication roles.
 *
 * @property int $id
 * @property int $user_id
 * @property StaffType $staff_type
 * @property string|null $staff_number
 */
class Staff extends Model
{
    /** @use HasFactory<StaffFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'staff_type',
        'staff_number',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'staff_type' => StaffType::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
