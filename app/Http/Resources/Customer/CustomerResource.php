<?php

namespace App\Http\Resources\Customer;

use App\Http\Resources\Base\BaseApiResource;
use Illuminate\Http\Request;

/**
 * Customer API Resource
 * 
 * Transforms CustomerDTO data to API response format.
 * Removes Shopify internal fields and flattens nested structures.
 * 
 * Requirements: 17.4, 17.6, 17.7, 17.8
 */
class CustomerResource extends BaseApiResource
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
            'full_name' => $this->getFullName(),
            'phone' => $this->phone,
            'addresses' => AddressResource::collection($this->addresses),
            'default_address_id' => $this->defaultAddressId,
            'tags' => $this->tags,
            'accepts_marketing' => $this->acceptsMarketing,
            'has_addresses' => $this->hasAddresses(),
            'created_at' => $this->createdAt,
        ];
    }
}
