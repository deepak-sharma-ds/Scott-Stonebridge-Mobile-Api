<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\BaseApiRequest;

class AddCustomerAddressRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'address1'   => 'required|string|max:150',
            'address2'   => 'nullable|string|max:150',
            'city'       => 'required|string|max:100',
            'company'    => 'nullable|string|max:100',
            'country'    => 'required|string|max:100',
            'firstName'  => 'required|string|max:100',
            'lastName'   => 'required|string|max:100',
            'phone'      => 'nullable|string|max:20',
            'province'   => 'nullable|string|max:100',
            'zip'        => 'required|string|max:20',
        ];
    }
}
