<?php

namespace App\Models;

use App\Enums\Gender;
use App\Enums\StudentStatus;
use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A pupil's persistent identity: who this person is, and whether they are on the roll.
 *
 * This row is written once and then only amended for identity and lifecycle. It is NEVER
 * rewritten by an academic event. A promotion, a class change, a result or an attendance
 * record belongs to a table that can hold a history of what happened in a given academic
 * session, and a column on this table holding "the class they are in" would be a cache of
 * that history with no way to detect the cache going stale.
 *
 *     2024/2025 -> JSS 2 -> A
 *     2025/2026 -> JSS 3 -> A
 *     2026/2027 -> SS 1  -> B
 *
 * Those are three rows in a future enrollments table and one row here. Nothing in Module 04
 * puts a class, section or session on this model, and the migration has no column to put
 * them in.
 *
 * The name lives here rather than on the linked account, unlike staff. A staff record cannot
 * exist without a login, so Module 03 was able to keep the single copy of the name on users.
 * A pupil can exist with no account at all, so the name has to belong to the pupil. A linked
 * account's users.name is a login-facing label and is NOT this pupil's identity - it is not
 * read for display here and it is not kept in step with these fields.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $student_number
 * @property string $first_name
 * @property string|null $middle_name
 * @property string|null $last_name
 * @property Carbon|null $date_of_birth
 * @property Gender|null $gender
 * @property StudentStatus $status
 */
class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * user_id is NOT among them, on purpose. No Module 04 payload can create or repoint the
     * portal link: creating a link would mean creating a login, which is the account system
     * this module was built to avoid, and repointing one would let a holder of
     * students.update attach somebody else's login to a child. Provisioning a portal account
     * is the portal module's job, and it will add its own guarded path.
     *
     * @var list<string>
     */
    protected $fillable = [
        'student_number',
        'first_name',
        'middle_name',
        'last_name',
        'date_of_birth',
        'gender',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'gender' => Gender::class,
            'status' => StudentStatus::class,
        ];
    }

    /**
     * Normalise the student number to a trimmed, upper-case string.
     *
     * A second line of defence, exactly as on Staff. The requests normalise in
     * prepareForValidation() so the value checked for uniqueness is the value stored;
     * normalising only here would let "stu-01" pass a check against a stored "STU-01",
     * collide on the index, and surface a client's typo as a 500.
     *
     * @return Attribute<string, string|null>
     */
    protected function studentNumber(): Attribute
    {
        return Attribute::make(
            set: function (?string $value): ?string {
                if (is_null($value)) {
                    return null;
                }

                $normalised = mb_strtoupper(trim($value));

                // An empty string is not a student number. Storing '' would satisfy the
                // unique index while behaving like null, and on SQL Server a unique index
                // permits only one null per column, so the index would then allow exactly one
                // such row in the whole school.
                return $normalised === '' ? null : $normalised;
            },
        );
    }

    /**
     * Trim the name parts, so a stored name is never padded with the whitespace a form
     * submitted.
     *
     * @return Attribute<string, string|null>
     */
    protected function firstName(): Attribute
    {
        return $this->trimmedNamePart();
    }

    /**
     * @return Attribute<string, string|null>
     */
    protected function middleName(): Attribute
    {
        return $this->trimmedNamePart();
    }

    /**
     * @return Attribute<string, string|null>
     */
    protected function lastName(): Attribute
    {
        return $this->trimmedNamePart();
    }

    /**
     * The reserved portal link, when this pupil has an account.
     *
     * Null for most pupils in Module 04, and that is the point: identity does not depend on
     * having a login.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The pupil's name as one string, for display and for search.
     *
     * A method rather than an accessor, so it does not appear as a phantom `full_name`
     * attribute on the model: the resource composes its own field list, and an accessor
     * would be a second, silently divergent answer to the same question.
     */
    public function fullName(): string
    {
        return collect([$this->first_name, $this->middle_name, $this->last_name])
            ->filter()
            ->implode(' ');
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * True once the pupil has left for good, whether they graduated or withdrew.
     */
    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * @return Attribute<string, string|null>
     */
    protected function trimmedNamePart(): Attribute
    {
        return Attribute::make(
            set: function (?string $value): ?string {
                if (is_null($value)) {
                    return null;
                }

                $trimmed = trim($value);

                // Blank is absence, not the empty string, so a missing middle name is null
                // rather than ''. Otherwise a list would render "Ada  Okonkwo" for a pupil
                // who never had a middle name.
                return $trimmed === '' ? null : $trimmed;
            },
        );
    }
}
