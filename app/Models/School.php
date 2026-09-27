<?php

namespace App\Models;

use App\Enums\SchoolStatus;
use Database\Factories\SchoolFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The one school this installation manages.
 *
 * This is a singleton CONFIGURATION record. It is not a tenant: no other table holds a
 * school_id, because a constant foreign key would be a column that only ever contains 1
 * and would make the schema look like a multi-school system that this project is not.
 *
 * The uniqueness of the record is a DATABASE constraint, not an application convention:
 * singleton_key is NOT NULL, defaults to 1 and is uniquely indexed, so a second school row
 * is rejected however it is written. See the schools migration for why the nullable
 * active_marker technique used by sessions and terms is the wrong tool here.
 *
 * @property int $id
 * @property string $name
 * @property string $short_name
 * @property SchoolStatus $status
 */
class School extends Model
{
    /** @use HasFactory<SchoolFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * singleton_key is intentionally absent: it is a structural constant, never client
     * supplied, and its presence in $fillable would only invite mistakes.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'short_name',
        'motto',
        'email',
        'phone',
        'alternate_phone',
        'website',
        'address_line1',
        'city',
        'state',
        'country',
        'principal_name',
        'registration_number',
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
            'status' => SchoolStatus::class,
        ];
    }

    /**
     * Force the structural constant on every write, including a raw mass assignment that
     * did not mention it. A school row without the key would defeat the unique index that
     * is the only thing making this table a singleton, so the value is never left to
     * chance and never comes from a client.
     */
    protected static function booted(): void
    {
        static::creating(function (self $school): void {
            $school->singleton_key = true;
        });
    }

    /**
     * Short names appear in report headings and on every printed document, so normalise
     * them: "gis" and " GIS " must not produce two different school names on the same
     * page.
     *
     * Null is passed through unchanged. Both this and website() are optional on the
     * profile, and a seeder reading config/school.php passes whatever is configured,
     * including nothing at all. Coercing null to "" would store an empty string, which
     * reads back as a name the school actually has.
     */
    protected function shortName(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => is_null($value) ? null : mb_strtoupper(trim($value)),
        );
    }

    /**
     * A website is compared and displayed, so store it folded to lower case without a
     * scheme change, letting the Form Request validate any scheme the caller supplied.
     *
     * Null is passed through unchanged, for the reason given on shortName().
     */
    protected function website(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => is_null($value) ? null : mb_strtolower(trim($value)),
        );
    }

    /**
     * The school profile, or null before it has been configured.
     *
     * Deliberately does not filter on status: there is exactly one row, and a school that
     * has marked itself INACTIVE is still the school. Treating that as "not configured"
     * would silently hide the profile and strand the academic context.
     */
    public static function current(): ?self
    {
        return self::query()->first();
    }
}
