<?php

namespace App\Http\Requests\Product;

use App\Http\Requests\BaseApiRequest;

class SearchProductsRequest extends BaseApiRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge($this->paginationRules(), [
            'query' => ['required', 'string', 'min:1', 'max:255'],
            'product_type' => ['sometimes', 'string', 'max:255'],
            'vendor' => ['sometimes', 'string', 'max:255'],
        ]);
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'query.required' => 'The search query is required.',
            'query.min' => 'The search query must be at least 1 character.',
            'query.max' => 'The search query cannot exceed 255 characters.',
        ]);
    }
}
