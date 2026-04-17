<?php

namespace App\Http\Resources\Profile;

use App\Http\Resources\Base\BaseApiResource;
use Illuminate\Http\Request;

/**
 * Profile API Resource
 * 
 * Transforms ProfileDTO data to API response format.
 * Includes customer details and all associated addresses.
 * 
 * Requirements: 12.2, 12.9, 12.10, 12.11
 */
class ProfileResource extends BaseApiResource
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
            'email' => $this->email,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'phone' => $this->phone,
            'addresses' => AddressResource::collection($this->addresses),
            'default_address_id' => $this->defaultAddressId,
            'accepts_marketing' => $this->acceptsMarketing,
            'created_at' => $this->createdAt,
        ];
    }
}
