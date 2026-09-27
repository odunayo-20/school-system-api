<?php

namespace App\Services\Academic;

use App\Enums\SchoolStatus;
use App\Models\School;

/**
 * Read and write the single school profile.
 *
 * There is no create and no delete. The profile is the configuration every other module
 * hangs off, and an installation with no school profile is not a valid state to reach by
 * API: there would be nothing to attach a session, a class or a report to. So PUT both
 * creates the profile when it is absent and amends it when it is present, and the only
 * permission required is school.update. This is safe without a separate school.create
 * permission precisely because the record is a singleton that no caller can point at
 * another tenant's row.
 */
class SchoolConfigurationService
{
    /**
     * The profile, or null on an installation that has not been configured yet.
     */
    public function profile(): ?School
    {
        return School::current();
    }

    /**
     * Create the profile if it does not exist, otherwise amend it.
     *
     * Deliberately a single upsert rather than a create-then-update pair: the endpoint is
     * the same PUT either way, and a client that had to know whether the school existed
     * before it could edit it would have to make a pointless extra request to find out.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(array $attributes): School
    {
        // Set explicitly rather than left to the column default. A model created without
        // it would be returned to the resource with a null status even though the database
        // had stored ACTIVE, and every reader of that payload would then fail on it.
        $attributes['status'] ??= SchoolStatus::ACTIVE;

        $school = School::current();

        if ($school) {
            $school->fill($attributes)->save();

            return $school;
        }

        return School::query()->create($attributes);
    }
}
