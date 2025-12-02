<?php

namespace App\Http\Requests\Orders;

use App\Http\Requests\BaseApiRequest;

class GetOrdersRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'limit'  => 'sometimes|integer|min:1|max:250',
            'after'  => 'sometimes|string|nullable',
            'filter' => 'required|string',
        ];
    }
}
