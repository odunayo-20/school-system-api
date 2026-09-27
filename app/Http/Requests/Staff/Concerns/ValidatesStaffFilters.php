<?php

namespace App\Http\Requests\Staff\Concerns;

use App\Enums\EmploymentStatus;
use App\Enums\StaffType;
use App\Enums\UserStatus;
use Illuminate\Validation\Rule;

/**
 * The shared parts of the staff list filters.
 *
 * Only the rules live here. The audit's note about has_account is worth repeating here,
 * because this is where somebody would otherwise "fix" it.
 *
 * has_account is honest but cannot be selective. Module 01 defined staff.user_id as NOT
 * NULL and UNIQUE - one staff record per login - so every staff row necessarily has an
 * account. has_account=true therefore returns everything and has_account=false returns
 * nothing. That is not a bug to paper over with a second status-like column: the correct
 * response to "every staff has an account" is a filter that says so, and a test that
 * asserts the empty result so the constraint stays pinned down. If a future module ever
 * lets an employment record exist without a login, this filter starts returning a real
 * subset for free.
 */
trait ValidatesStaffFilters
{
    /**
     * @return array<string, mixed>
     */
    protected function staffFilterRules(): array
    {
        return [
            'staff_type' => ['sometimes', 'string', Rule::enum(StaffType::class)],
            'status' => ['sometimes', 'string', Rule::enum(EmploymentStatus::class)],
            'account_status' => ['sometimes', 'string', Rule::enum(UserStatus::class)],
            'has_account' => self::booleanFlag(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function staffFilterMessages(): array
    {
        return [
            'staff_type.enum' => 'The staff type must be one of: '.implode(', ', StaffType::values()).'.',
            'status.enum' => 'The status must be one of: '.implode(', ', EmploymentStatus::values()).'.',
            'account_status.enum' => 'The account status must be one of: '.implode(', ', UserStatus::values()).'.',
            'has_account.in' => 'The has_account filter must be one of: 1, 0, true, false.',
        ];
    }
}
