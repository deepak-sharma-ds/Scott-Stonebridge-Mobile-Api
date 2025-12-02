<?php

namespace App\Http\Requests\Wishlist;

use App\Http\Requests\BaseApiRequest;

class AddWishlistRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'product_id' => 'required|string',
        ];
    }
}
