<?php

namespace App\Models;

use App\Enums\EmploymentStatus;
use App\Enums\StaffType;
use Database\Factories\StaffFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Classification and employment record for a user whose role is STAFF. Teaching and
 * non-teaching staff are staff types, not authentication roles.
 *
 * The person's identity is users.name and users.email and is deliberately NOT duplicated
 * here: two sources of truth for a name is two things that can disagree, and the only
 * question the duplicate would answer is which one is wrong.
 *
 * @property int $id
 * @property int $user_id
 * @property StaffType $staff_type
 * @property string|null $staff_number
 * @property EmploymentStatus $status
 * @property Carbon|null $employment_date
 * @property string|null $phone
 * @property string|null $designation
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
        'status',
        'employment_date',
        'phone',
        'designation',
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
            'status' => EmploymentStatus::class,
            'employment_date' => 'date',
        ];
    }

    /**
     * Normalise the staff number to a trimmed, upper-case string.
     *
     * This is a second line of defence, not the primary mechanism. The requests normalise
     * in prepareForValidation() so the value that is checked for uniqueness is the value
     * that gets stored; normalising only here would let "sta-01" pass a uniqueness check
     * against a stored "STA-01", collide on the index, and surface a client's typo as a
     * 500. Both layers are correct: the request guarantees the checked value is the stored
     * value, and this guarantees a writer that does not go through a request - a seeder, a
     * factory, a tinker session - cannot store a number that would never match one typed
     * by hand.
     *
     * @return Attribute<string, string|null>
     */
    protected function staffNumber(): Attribute
    {
        return Attribute::make(
            set: function (?string $value): ?string {
                if (is_null($value)) {
                    return null;
                }

                $normalised = mb_strtoupper(trim($value));

                // An empty string is not a staff number. Storing '' would satisfy the NOT
                // NULL column while behaving like null, and the unique index would then
                // allow only one such row in the whole school.
                return $normalised === '' ? null : $normalised;
            },
        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function isTerminated(): bool
    {
        return $this->status->isTerminated();
    }
}
