<?php

namespace App\Http\Resources\Profile;

use App\Http\Resources\Base\BaseApiResource;
use Illuminate\Http\Request;

/**
 * Address API Resource
 * 
 * Transforms AddressDTO data to API response format.
 * Uses snake_case field naming for consistency.
 * 
 * Requirements: 12.3, 12.9, 12.10, 12.11
 */
class AddressResource extends BaseApiResource
{
    /**
     * Transform the resource into an array.
     * 
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'address1' => $this->address1,
            'address2' => $this->address2,
            'city' => $this->city,
            'province' => $this->province,
            'country' => $this->country,
            'zip' => $this->zip,
            'phone' => $this->phone,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'company' => $this->company,
            'is_default' => $this->isDefault,
        ];
    }
}
