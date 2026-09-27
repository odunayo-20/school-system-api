<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The only representation of a user that leaves the API. Fields are whitelisted
 * explicitly: the model is never returned directly, so an internal column added
 * later cannot leak by accident.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status->value,
            'role' => $this->roleEnum()?->value,
            'staff_type' => $this->when(
                $this->isStaff(),
                fn (): ?string => $this->staffType()?->value
            ),
            'email_verified' => $this->email_verified_at !== null,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'permissions' => $this->permissionNames(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
