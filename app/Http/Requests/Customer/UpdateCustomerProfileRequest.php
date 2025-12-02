<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\BaseApiRequest;

class UpdateCustomerProfileRequest extends BaseApiRequest
{
    public function rules(): array
    {
        /**
         * Validation rules for updating customer profile
         */
        return [
            'firstName' => 'required|string|max:100',
            'lastName'  => 'required|string|max:100',
            'email'     => 'required|email|max:255',
            'phone'     => 'required|string|max:100',
        ];
    }
}
