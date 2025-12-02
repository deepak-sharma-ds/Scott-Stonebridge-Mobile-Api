<?php

namespace App\Http\Requests\Wishlist;

use App\Http\Requests\BaseApiRequest;

class RemoveWishlistRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'product_id'  => 'required|string',
            'customer_id' => 'required|string',
        ];
    }
}
