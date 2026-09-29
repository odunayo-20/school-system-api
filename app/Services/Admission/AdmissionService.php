<?php

namespace App\Services\Admission;

use App\Enums\AdmissionStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Admission;
use App\Services\Student\StudentService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reading and writing admission records, and the one transition that matters: turning an
 * accepted applicant into a pupil.
 *
 * StudentService is injected and called, never re-implemented here. Admission owns the
 * decision; Student owns what a pupil's row looks like and how its number is derived. Two
 * services agreeing to that boundary is the whole reason this class does not duplicate
 * StudentService::create()'s transaction, its reservation-number trick, or its terminal
 * rule - it simply calls it.
 */
class AdmissionService
{
    /**
     * The prefix of a derived admission number.
     */
    public const NUMBER_PREFIX = 'ADM-';

    /**
     * How many digits a derived number is padded to. Cosmetic, exactly as for students and
     * staff: the number is derived from a primary key that is already unique.
     */
    protected const NUMBER_PAD = 4;

    public function __construct(protected StudentService $students) {}

    /**
     * @param  array{search?: string, status?: string, academic_session_id?: int, entry_class_level_id?: int, per_page?: int}  $filters
     * @return LengthAwarePaginator<array-key, Admission>
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return $this->query($filters)
            // Newest application first. Unlike the student roll (read alphabetically, the
            // way a register is read) an admission list is a queue of decisions to work
            // through, and the question it answers is "what came in lately", not "who is
            // this alphabetically".
            ->orderByDesc('admissions.created_at')
            ->orderByDesc('admissions.id')
            ->paginate(perPage: $filters['per_page'] ?? 15)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Admission>
     */
    protected function query(array $filters): Builder
    {
        return Admission::query()
            ->with(['student', 'academicSession', 'entryClassLevel'])
            ->when($filters['status'] ?? null, fn (Builder $q, string $status): Builder => $q->where('admissions.status', $status))
            ->when($filters['academic_session_id'] ?? null, fn (Builder $q, int $id): Builder => $q->where('admissions.academic_session_id', $id))
            ->when($filters['entry_class_level_id'] ?? null, fn (Builder $q, int $id): Builder => $q->where('admissions.entry_class_level_id', $id))
            ->when($filters['search'] ?? null, function (Builder $q, string $search): Builder {
                // Escaped and lower-cased for the same reason as the student roll search:
                // the wildcards belong to this query, not to whatever the caller typed, and
                // matching has to be collation-independent across the four supported
                // drivers.
                $term = '%'.str_replace(
                    ['!', '%', '_'],
                    ['!!', '!%', '!_'],
                    mb_strtolower(trim($search))
                ).'%';

                return $q->where(function (Builder $q) use ($term): void {
                    $q->whereRaw("lower(admissions.admission_number) like ? escape '!'", [$term])
                        ->orWhereRaw("lower(admissions.first_name) like ? escape '!'", [$term])
                        ->orWhereRaw("lower(admissions.middle_name) like ? escape '!'", [$term])
                        ->orWhereRaw("lower(admissions.last_name) like ? escape '!'", [$term]);
                });
            });
    }

    /**
     * Record a new admission. PENDING, always - see StoreAdmissionRequest for why no status
     * is accepted here.
     *
     * Transactional for the same reason StudentService::create() is: deriving the number
     * needs a second statement once the id is known, and a failure between the two must not
     * commit a record whose public reference is an internal reservation.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Admission
    {
        $requested = $attributes['admission_number'] ?? null;

        $admission = DB::transaction(function () use ($attributes, $requested): Admission {
            $admission = Admission::query()->create([
                ...$attributes,
                'admission_number' => $requested ?? $this->reservationNumber(),
                'status' => AdmissionStatus::PENDING,
            ]);

            if (is_null($requested)) {
                $admission->forceFill([
                    'admission_number' => $this->deriveAdmissionNumber($admission->getKey()),
                ])->save();
            }

            return $admission;
        });

        return $admission->load(['student', 'academicSession', 'entryClassLevel']);
    }

    /**
     * Amend an admission. Identity, the target session, the entry level and notes only -
     * never the status, which has no key to mass-assign it through in the first place. See
     * UpdateAdmissionRequest.
     *
     * A decided admission is READ ONLY in full, exactly as a departed pupil's record is in
     * StudentService::update(). The decision is a fact about a moment; a later amend must
     * not be able to rewrite what the applicant looked like when it was made.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Admission $admission, array $attributes): Admission
    {
        if ($admission->isTerminal()) {
            throw new BusinessRuleViolation(
                'This admission has already been decided, so the record can no longer be amended.'
            );
        }

        $admission->fill($attributes);
        $admission->save();

        return $admission->load(['student', 'academicSession', 'entryClassLevel']);
    }

    /**
     * Accept the applicant: create the pupil and close the admission in one transaction.
     *
     * Only reachable from PENDING - see assertPending(). That single guard is also what
     * prevents admitting the same admission twice: the first call moves the status to
     * ADMITTED, and the second never reaches the student-creation code at all. There is
     * deliberately no separate "already has a student_id" check, because the status guard
     * makes that state unreachable in the first place.
     *
     * Delegates entirely to StudentService::create() for what a pupil's row looks like and
     * how its number is derived - this method supplies only the identity fields it already
     * holds as the applicant's snapshot.
     */
    public function admit(Admission $admission): Admission
    {
        $this->assertPending($admission, 'admitted');

        return DB::transaction(function () use ($admission): Admission {
            $student = $this->students->create([
                'first_name' => $admission->first_name,
                'middle_name' => $admission->middle_name,
                'last_name' => $admission->last_name,
                'date_of_birth' => $admission->date_of_birth,
                'gender' => $admission->gender?->value,
            ]);

            $admission->forceFill([
                'student_id' => $student->getKey(),
                'status' => AdmissionStatus::ADMITTED,
                'decided_at' => now(),
            ])->save();

            return $admission->load(['student', 'academicSession', 'entryClassLevel']);
        });
    }

    /**
     * Decline the applicant. No Student is created or touched.
     */
    public function reject(Admission $admission, ?string $notes = null): Admission
    {
        $this->assertPending($admission, 'rejected');

        $admission->forceFill([
            'status' => AdmissionStatus::REJECTED,
            'decided_at' => now(),
            'notes' => $notes ?? $admission->notes,
        ])->save();

        return $admission->load(['student', 'academicSession', 'entryClassLevel']);
    }

    /**
     * Record that the applicant withdrew before a decision was made.
     */
    public function withdraw(Admission $admission, ?string $notes = null): Admission
    {
        $this->assertPending($admission, 'withdrawn');

        $admission->forceFill([
            'status' => AdmissionStatus::WITHDRAWN,
            'decided_at' => now(),
            'notes' => $notes ?? $admission->notes,
        ])->save();

        return $admission->load(['student', 'academicSession', 'entryClassLevel']);
    }

    /**
     * The one place every transition checks its precondition, so admit(), reject() and
     * withdraw() cannot drift into three different answers to "can this admission still be
     * decided?".
     *
     * Unlike Module 03's staff employment transitions, repeating a transition on a record
     * that already has that outcome is NOT treated as an idempotent success. An admission
     * decision is a one-shot event with a side effect (a created pupil, in the ADMITTED
     * case) rather than a reversible toggle like employment status, so "admit somebody
     * already admitted" is refused exactly like "reject somebody already admitted" - both
     * are attempts to change a decision that has already been made, not a retry of the same
     * one.
     */
    protected function assertPending(Admission $admission, string $verb): void
    {
        if (! $admission->isPending()) {
            throw new BusinessRuleViolation(
                "This admission has already been decided ({$admission->status->value}), so it cannot be {$verb}."
            );
        }
    }

    /**
     * A readable, unique admission number derived from the record's own primary key.
     */
    public function deriveAdmissionNumber(int $admissionId): string
    {
        return self::NUMBER_PREFIX.Str::padLeft((string) $admissionId, self::NUMBER_PAD, '0', STR_PAD_LEFT);
    }

    /**
     * A throwaway, per-insert-unique reference for the instant before the id is known - the
     * same UUID reservation StudentService uses, and for the identical reason: neither a
     * fixed placeholder nor null is safe under concurrency or driver differences. See
     * StudentService::reservationNumber() for the full reasoning.
     */
    protected function reservationNumber(): string
    {
        return self::NUMBER_PREFIX.'TMP-'.Str::upper(Str::uuid()->toString());
    }
}
