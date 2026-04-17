<?php

namespace App\Http\Requests\Product;

use App\Http\Requests\BaseApiRequest;

class GetProductsRequest extends BaseApiRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge($this->paginationRules(), [
            'filters' => ['sometimes', 'array'],
            'filters.available' => ['sometimes', 'boolean'],
            'filters.product_type' => ['sometimes', 'string', 'max:255'],
            'filters.vendor' => ['sometimes', 'string', 'max:255'],
            'filters.tag' => ['sometimes', 'string', 'max:255'],
            'sort_key' => ['sometimes', 'string', 'in:TITLE,PRICE,CREATED_AT,UPDATED_AT,BEST_SELLING'],
            'reverse' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'filters.array' => 'The filters must be a valid array.',
            'filters.available.boolean' => 'The available filter must be true or false.',
            'sort_key.in' => 'The sort key must be one of: TITLE, PRICE, CREATED_AT, UPDATED_AT, BEST_SELLING.',
            'reverse.boolean' => 'The reverse parameter must be true or false.',
        ]);
    }
}
