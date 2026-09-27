<?php

namespace App\Http\Resources\Academic;

use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The only representation of the school profile that leaves the API. Fields are
 * whitelisted explicitly, so a column added to the table later cannot leak by accident.
 *
 * singleton_key is never exposed: it is a structural constant with no meaning for a client,
 * and publishing an internal uniqueness token invites clients to depend on it.
 *
 * @mixin School
 */
class SchoolResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'short_name' => $this->short_name,
            'motto' => $this->motto,
            'email' => $this->email,
            'phone' => $this->phone,
            'alternate_phone' => $this->alternate_phone,
            'website' => $this->website,
            'address_line1' => $this->address_line1,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'principal_name' => $this->principal_name,
            'registration_number' => $this->registration_number,
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
