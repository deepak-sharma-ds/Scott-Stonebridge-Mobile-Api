<?php

namespace App\Http\Requests\Products;

use App\Http\Requests\BaseApiRequest;

class ProductDetailsRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'handle' => 'required|string',
        ];
    }
}
