<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\BaseApiRequest;

class DeleteCustomerAddressRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'address_id' => 'required|string',
        ];
    }
}
