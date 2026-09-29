<?php

namespace App\Services\Student;

use App\Enums\StudentStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Student;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reading and writing the pupil roll.
 *
 * The whole module is one table and one service. There is no account to create, no
 * transaction spanning two tables, and no transition with a side effect beyond the record -
 * so the operations here are thin, and the value this class adds over putting them in the
 * controller is the two rules that must not be forgotten wherever they are called from: the
 * terminal lifecycle, and deriving a student number that cannot collide.
 *
 * There is deliberately NO user, role, UserStatus or authentication code in this file.
 * Creating a login is not this module's job, and importing Module 01's concerns here to do
 * it would be the first step towards the account system this module was built to avoid.
 */
class StudentService
{
    /**
     * The prefix of a derived student number.
     */
    public const NUMBER_PREFIX = 'STU-';

    /**
     * How many digits a derived number is padded to. Cosmetic: the number is derived from a
     * primary key that is already unique, so this only controls how it reads.
     */
    protected const NUMBER_PAD = 4;

    /**
     * @param  array{search?: string, status?: string, gender?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<array-key, Student>
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return $this->query($filters)
            // A roll is read alphabetically, the way a school reads it, rather than
            // newest-first the way an audit log would be. Surname first is also how a
            // registrar's own handwriting sorts it.
            //
            // A pupil with no surname is not sorted as if their surname were the empty
            // string at the top of the alphabet: the CASE expression puts them last, for
            // the same reason Module 03's staff list does. Expressed as a CASE rather than
            // NULLS LAST so it is the same query on all four supported drivers.
            ->orderByRaw('case when students.last_name is null then 1 else 0 end')
            ->orderBy('students.last_name')
            ->orderBy('students.first_name')
            // A final tiebreak on the primary key, so two pupils who share a name cannot
            // swap places between one page and the next. Without it, paging through a roll
            // with duplicate names can show the same pupil twice and skip another.
            ->orderBy('students.id')
            ->paginate(perPage: $filters['per_page'] ?? 15)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Student>
     */
    protected function query(array $filters): Builder
    {
        return Student::query()
            // The linked account is loaded, not joined. There is nothing to search or sort
            // on it - a pupil's name is on the pupil row, which is the whole point of the
            // split - so a join would be a second, redundant path to the same data.
            ->with('user')
            ->when($filters['status'] ?? null, fn (Builder $q, string $status): Builder => $q->where('students.status', $status))
            ->when($filters['gender'] ?? null, fn (Builder $q, string $gender): Builder => $q->where('students.gender', $gender))
            ->when($filters['search'] ?? null, function (Builder $q, string $search): Builder {
                // The user's own wildcards are escaped, because the % around the term is added
                // by this query and anything the user typed has to be matched literally.
                // Without this a search for "%" becomes "%%%" and matches every pupil on the
                // roll, which turns a box meant to narrow the list into a way to dump it.
                //
                // "!" is the escape character rather than a backslash because MySQL treats a
                // backslash inside a string literal as an escape of its own, so ESCAPE '\'
                // is not portable - it is rejected on some drivers and silently means
                // something else on others. "!" has no meaning in a MySQL string literal, so
                // the same clause behaves identically on all four supported drivers.
                $term = '%'.str_replace(
                    ['!', '%', '_'],
                    ['!!', '!%', '!_'],
                    mb_strtolower(trim($search))
                ).'%';

                // Lowercased on both sides so "Ada" and "ada" match identically whatever the
                // database collation happens to be, which differs between the four supported
                // drivers.
                return $q->where(function (Builder $q) use ($term): void {
                    $q->whereRaw("lower(students.student_number) like ? escape '!'", [$term])
                        ->orWhereRaw("lower(students.first_name) like ? escape '!'", [$term])
                        ->orWhereRaw("lower(students.middle_name) like ? escape '!'", [$term])
                        ->orWhereRaw("lower(students.last_name) like ? escape '!'", [$term]);
                });
            });
    }

    /**
     * Add a pupil to the roll.
     *
     * There is no account to create, so this writes one row and one derived correction to
     * that row - but those are two statements, and a transaction is still warranted: without
     * one, a failure between them would commit a pupil whose public number is an internal
     * reservation. See reservationNumber() for the other half of that problem.
     *
     * A new pupil is ACTIVE, because a child is put on the roll to be taught, and a record
     * born INACTIVE would be a record that needs a second call before it means anything.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Student
    {
        // A client-supplied number is honoured, because student_number is optional and a
        // school with its own numbering scheme supplied it in good faith. Validating a value
        // and then overwriting it would be the worst of both: the client would be told
        // their number was accepted, and it would not be on the record.
        //
        // When the client omits one, a number is derived from the row's own primary key
        // rather than from count() + 1. count() + 1 is wrong under concurrency: two
        // simultaneous creates read the same count, derive the same number, and the loser's
        // insert dies on the unique index as a 500. A primary key cannot be read twice, so
        // the derived value cannot collide.
        $requested = $attributes['student_number'] ?? null;

        $student = DB::transaction(function () use ($attributes, $requested): Student {
            $student = Student::query()->create([
                ...$attributes,
                // A distinct reservation per insert, for the instant before the id is known.
                // See reservationNumber() for why this cannot be a fixed string or a null.
                'student_number' => $requested ?? $this->reservationNumber(),
                'status' => StudentStatus::ACTIVE,
            ]);

            // Only the derived path needs a second statement: the number depends on the id
            // the insert just produced, which did not exist when the row was written.
            if (is_null($requested)) {
                $student->forceFill([
                    'student_number' => $this->deriveStudentNumber($student->getKey()),
                ])->save();
            }

            return $student;
        });

        return $student->load('user');
    }

    /**
     * Amend a pupil record.
     *
     * A departed pupil is READ ONLY in full, not merely protected against being reinstated.
     * The whole record freezes when a child leaves: a graduation or a withdrawal is a fact
     * about a date, and a later amend must not be able to rewrite what was recorded about
     * them afterwards.
     *
     * This is Module 03's rule exactly - a terminated employment cannot be amended either -
     * and it is deliberately stricter than "the status is the protected part". An earlier
     * draft of this service tried to let a client re-send the status it already had, on the
     * argument that a retried request should be safe. That is unreachable: this method
     * refuses every amend on a terminal record, including one that would change nothing, so
     * the special case could never fire. Refusing the retry is also the more honest answer -
     * a departed pupil's record is closed, and a client that gets a 422 learns that clearly
     * rather than being led to believe some amends are still permitted.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Student $student, array $attributes, ?StudentStatus $status = null): Student
    {
        if ($student->isTerminal()) {
            throw new BusinessRuleViolation(
                'This pupil has already left the school, so the record can no longer be amended.'
            );
        }

        $student->fill($attributes);

        if ($status !== null) {
            $student->status = $status;
        }

        $student->save();

        return $student->load('user');
    }

    /**
     * A readable, unique student number derived from the pupil's own primary key.
     *
     * Unique by construction rather than by query: the id is unique and each pupil gets a
     * distinct one, so two records can never derive the same number.
     */
    public function deriveStudentNumber(int $studentId): string
    {
        return self::NUMBER_PREFIX.Str::padLeft((string) $studentId, self::NUMBER_PAD, '0', STR_PAD_LEFT);
    }

    /**
     * A throwaway number for the instant between the row being created and its id being known.
     *
     * The derived number cannot be written in the same statement, because it depends on the
     * id the insert produces. So the insert has to carry *something* in a unique column, and
     * that something has to be DIFFERENT ON EVERY INSERT.
     *
     * This is a UUID, for two reasons, and both were learned the hard way:
     *
     * 1. A fixed placeholder string is not unique per insert. An earlier version of this
     *    service reserved "STU-PENDING" for every pupil, on the reasoning that no DERIVED
     *    number could ever equal it - true, and the wrong comparison. Two pupils added at the
     *    same moment both insert "STU-PENDING", the second one dies on the unique index, and
     *    the client gets a 500 for a request that was perfectly valid. The reasoning also
     *    claimed the reservation was unobservable "before the request returns", which is not
     *    a guarantee: without a transaction, an error between the two statements commits a
     *    pupil whose public number really is "STU-PENDING", forever.
     *
     * 2. NULL is not available either, even though the column is nullable and NULL looks like
     *    the obvious "no value yet". Under SQL Server a UNIQUE index treats NULL as one
     *    comparable value and permits only ONE null row per column, so every pupil after the
     *    first would collide. SQLite, MySQL and PostgreSQL all treat NULLs as distinct and
     *    would have been fine - which is exactly why this is a trap: the fix works on the
     *    driver the tests run and fails on a driver the project configures. The same
     *    restriction is why students.user_id being nullable-and-unique means Module 04 could
     *    not have leaned on NULL here even if the numbers were derived some other way.
     *
     * A UUID is unique by construction on all four drivers, needs no extra query and no lock,
     * and the transaction in create() guarantees it is never visible outside the insert.
     */
    protected function reservationNumber(): string
    {
        return self::NUMBER_PREFIX.'TMP-'.Str::upper(Str::uuid()->toString());
    }
}
