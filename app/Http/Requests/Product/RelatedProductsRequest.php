<?php

namespace App\Http\Requests\Product;

use App\Http\Requests\BaseApiRequest;

class RelatedProductsRequest extends BaseApiRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'string'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'product_id.required' => 'The product ID is required.',
            'limit.max' => 'The limit cannot exceed 20.',
        ]);
    }
}
