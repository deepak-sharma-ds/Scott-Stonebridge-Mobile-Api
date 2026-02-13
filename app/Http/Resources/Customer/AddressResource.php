<?php

namespace App\Http\Resources\Customer;

use App\Http\Resources\Base\BaseApiResource;
use Illuminate\Http\Request;

/**
 * Address API Resource
 * 
 * Transforms AddressDTO data to API response format.
 * Removes Shopify internal fields and provides clean address structure.
 * 
 * Requirements: 17.4, 17.6, 17.7, 17.8
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
        ];
    }
}
