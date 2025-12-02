<?php

namespace App\Http\Requests\Products;

use App\Http\Requests\BaseApiRequest;

class ProductListRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'limit'      => 'sometimes|integer|min:1|max:250',
            'after'      => 'sometimes|string|nullable',
            'collection' => 'sometimes|string|nullable',
            'sort'       => 'sometimes|string|in:newest,oldest,low_price,high_price',
            'tag'        => 'sometimes|string|nullable',
            'search'     => 'sometimes|string|nullable',
            'filters'    => 'sometimes|array',
        ];
    }
}
