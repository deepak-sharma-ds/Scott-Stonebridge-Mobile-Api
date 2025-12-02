<?php

namespace App\Http\Requests\Orders;

use App\Http\Requests\BaseApiRequest;

class OrderDetailsRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'order_id' => 'required|string',
        ];
    }
}
