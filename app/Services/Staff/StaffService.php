<?php

namespace App\Services\Staff;

use App\Enums\EmploymentStatus;
use App\Enums\Role as RoleEnum;
use App\Enums\UserStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class StaffService
{
    /**
     * The prefix of a derived staff number.
     */
    public const NUMBER_PREFIX = 'STAFF-';

    /**
     * How many digits a derived number is padded to. Cosmetic: the number is derived from a
     * primary key that is already unique, so this only controls how it reads.
     */
    protected const NUMBER_PAD = 4;

    /**
     * @param  array{search?: string, staff_type?: string, status?: string, account_status?: string, has_account?: bool, per_page?: int}  $filters
     * @return LengthAwarePaginator<array-key, Staff>
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        return $this->query($filters)
            // Most recently appointed first, because the question a school opens a staff
            // list to answer is "who joined us lately". A member with no employment date
            // sorts last rather than first: null is not the newest date, it is the absence
            // of one, and letting it lead would put every un-dated record at the top of
            // page one forever. Expressed as a CASE expression rather than NULLS LAST so it
            // is the same query on all four supported drivers.
            ->orderByRaw('case when staff.employment_date is null then 1 else 0 end')
            ->orderByDesc('staff.employment_date')
            // Tiebreak on the person's name, so two members appointed on the same day keep
            // a stable relative order and paging cannot show one of them twice.
            ->orderBy('users.name')
            ->paginate(perPage: $filters['per_page'] ?? 15)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Staff>
     */
    protected function query(array $filters): Builder
    {
        // Joined rather than searched through whereHas because the ordering needs
        // users.name too, and joining once serves both. staff.* is selected explicitly so
        // the two tables' id/created_at/updated_at columns do not shadow one another.
        return Staff::query()
            ->select('staff.*')
            ->leftJoin('users', 'users.id', '=', 'staff.user_id')
            ->with('user')
            ->when($filters['staff_type'] ?? null, fn (Builder $q, string $type): Builder => $q->where('staff.staff_type', $type))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status): Builder => $q->where('staff.status', $status))
            ->when($filters['account_status'] ?? null, fn (Builder $q, string $status): Builder => $q->where('users.status', $status))
            // An existence check on the joined account, not a guess. Because staff.user_id is
            // NOT NULL this cannot currently exclude anything - see ValidatesStaffFilters -
            // but it is the honest expression of the question and starts returning a real
            // subset if that ever changes.
            ->when(
                array_key_exists('has_account', $filters) && $filters['has_account'] === false,
                fn (Builder $q): Builder => $q->whereNull('users.id'),
            )
            ->when($filters['search'] ?? null, function (Builder $q, string $search): Builder {
                $term = '%'.mb_strtolower(trim($search)).'%';

                // Lowercased on both sides so "Amina" and "amina" match identically whatever
                // the database collation happens to be, which differs between the four
                // supported drivers.
                return $q->where(function (Builder $q) use ($term): void {
                    $q->whereRaw('lower(staff.staff_number) like ?', [$term])
                        ->orWhereRaw('lower(users.name) like ?', [$term])
                        ->orWhereRaw('lower(users.email) like ?', [$term]);
                });
            });
    }

    /**
     * Create a staff record and the login account behind it, in one transaction.
     *
     * The transaction is not a nicety. staff.user_id is NOT NULL, so the staff row cannot be
     * written before the user exists and cannot outlive a failure to create one; without the
     * transaction a rejected second insert would leave an orphaned login that no staff
     * record refers to and that nobody can explain.
     *
     * The role is assigned here, in code, and is not an accepted request field. A payload
     * cannot ask for SUPER_ADMIN because there is no key to ask with.
     *
     * @param  array{name: string, email: string, password: string}  $account
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $account, array $attributes): Staff
    {
        $role = Role::query()->where('name', RoleEnum::STAFF->value)->first();

        if (! $role) {
            throw new BusinessRuleViolation(
                'The STAFF role does not exist, so a staff account cannot be created. Run the role seeder.'
            );
        }

        return DB::transaction(function () use ($role, $account, $attributes): Staff {
            $user = new User;

            // forceFill, not fill: role_id, status and email_verified_at are not on the
            // user's $fillable, deliberately, because no request should ever mass-assign
            // them. This is the administrative path, and the role is fixed one line below
            // rather than read from the payload.
            //
            // The account is created already verified. An administrator is provisioning a
            // colleague's login, exactly as SuperAdminSeeder does, and requiring this
            // particular account to click a verification link nobody is watching for would
            // only produce locked-out staff.
            $user->forceFill([
                'name' => $account['name'],
                'email' => $account['email'],
                'password' => $account['password'],
                'role_id' => $role->getKey(),
                'status' => UserStatus::ACTIVE,
                'email_verified_at' => now(),
            ])->save();

            // Derived from the account's own primary key rather than from count() + 1.
            // count() + 1 is wrong under concurrency: two simultaneous creates read the same
            // count, derive the same number, and the loser's insert dies on the unique
            // index as a 500. A primary key cannot be read twice, so this cannot collide,
            // and it needs no table scan, no max() query and no lock.
            $attributes['staff_number'] ??= $this->deriveStaffNumber($user->getKey());

            $staff = Staff::query()->create([
                ...$attributes,
                'user_id' => $user->getKey(),
                'status' => EmploymentStatus::ACTIVE,
            ]);

            return $staff->load('user');
        });
    }

    /**
     * Amend a staff record.
     *
     * A terminated employment is immutable. The record and its history are kept, but the
     * fact that it ended does not change: a leaver cannot be reopened by a later amend,
     * which would put somebody back on a current staff list after they have left.
     *
     * Transactional for the same reason create() is. An amend writes two tables - the
     * display name lives on the account, everything else on the staff row - so a failure
     * between the two saves would leave somebody's name changed while the rest of the amend
     * was rolled back, or the reverse. The name is not kept on the staff table precisely so
     * this does not happen, and a half-applied amend is worse than a rejected one.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Staff $staff, array $attributes, ?EmploymentStatus $status = null): Staff
    {
        if ($staff->isTerminated()) {
            throw new BusinessRuleViolation(
                'This employment has been terminated, so the staff record can no longer be amended.'
            );
        }

        return DB::transaction(function () use ($staff, $attributes, $status): Staff {
            if (array_key_exists('name', $attributes) && $staff->user) {
                $staff->user->forceFill(['name' => $attributes['name']])->save();

                unset($attributes['name']);
            }

            $staff->fill($attributes);

            if ($status !== null) {
                $this->setStatus($staff, $status);
            }

            $staff->save();

            return $staff->load('user');
        });
    }

    /**
     * The one place employment status changes.
     *
     * The activate and deactivate endpoints and the amend endpoint all come through here,
     * so there is a single implementation of what a transition means rather than three
     * that have to agree.
     *
     * Reactivating a terminated record is refused, and so is any change to one. Employment
     * that has ended does not un-happen, and a status that could be reversed would make a
     * permanent departure indistinguishable from a long absence after the fact.
     *
     * Asking for the status the record already has is a success, not a conflict: "activate
     * somebody who is already active" is what a double-clicked button looks like, and
     * answering it 422 would teach a client that retrying is dangerous.
     */
    public function setStatus(Staff $staff, EmploymentStatus $status): Staff
    {
        if ($staff->isTerminated() && $status !== EmploymentStatus::TERMINATED) {
            throw new BusinessRuleViolation(
                'This employment has been terminated, so it cannot be made active or inactive again.'
            );
        }

        $staff->status = $status;

        return $staff;
    }

    /**
     * Return a staff member to active employment.
     */
    public function activate(Staff $staff): Staff
    {
        $this->setStatus($staff, EmploymentStatus::ACTIVE);
        $staff->save();

        return $staff->load('user');
    }

    /**
     * Record that a staff member is no longer actively employed.
     *
     * This touches staff.status and nothing else. It does not change users.status, does not
     * revoke tokens, and does not verify or unverify the address. Those are credential
     * decisions belonging to Module 01's users.* permissions, and a registrar recording a
     * departure must not be able to cut somebody's login off as a side effect of an HR
     * action.
     *
     * The consequence is real and is documented rather than engineered away: a staff member
     * deactivated here can still log in, because their account is a separate fact. Both
     * statuses are reported on the resource so the mismatch is visible.
     */
    public function deactivate(Staff $staff): Staff
    {
        $this->setStatus($staff, EmploymentStatus::INACTIVE);
        $staff->save();

        return $staff->load('user');
    }

    /**
     * A readable, unique staff number derived from an account's primary key.
     *
     * Unique by construction rather than by query: user_id is unique and each staff record
     * gets a distinct account, so two records can never derive the same number.
     */
    public function deriveStaffNumber(int $userId): string
    {
        return self::NUMBER_PREFIX.str_pad((string) $userId, self::NUMBER_PAD, '0', STR_PAD_LEFT);
    }
}
