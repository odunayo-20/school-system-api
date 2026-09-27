<?php

namespace App\Models;

use App\Enums\TermStatus;
use App\Models\Concerns\TracksSingleActiveRecord;
use Database\Factories\TermFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A term inside an academic session, e.g. "Second Term" of 2026/2027.
 *
 * A term carries its own academic_session_id rather than being reachable only through its
 * session. Terms are acted on individually (activate, complete, amend dates) and those
 * actions arrive as flat /api/v1/terms/{term} URLs, so the parent is a real piece of data
 * about the term rather than a nesting convenience.
 *
 * @property int $id
 * @property int $academic_session_id
 * @property string $name
 * @property int $term_number
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property TermStatus $status
 * @property bool|null $active_marker
 */
class Term extends Model
{
    /** @use HasFactory<TermFactory> */
    use HasFactory, TracksSingleActiveRecord;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'academic_session_id',
        'name',
        'term_number',
        'start_date',
        'end_date',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'academic_session_id' => 'integer',
            'term_number' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'status' => TermStatus::class,
            'active_marker' => 'boolean',
        ];
    }

    /**
     * term_number is the value the system orders and compares on, so a zero or negative
     * number is rejected here as well as in the Form Request: a term that cannot be
     * ordered is a corrupt record, and the model is the last line before the database.
     */
    protected function termNumber(): Attribute
    {
        return Attribute::make(
            set: fn (int $value): int => max(1, $value),
        );
    }

    /**
     * @return class-string<TermStatus>
     */
    protected function activeStatusEnum(): string
    {
        return TermStatus::class;
    }

    /**
     * @return BelongsTo<AcademicSession, $this>
     */
    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }
}
