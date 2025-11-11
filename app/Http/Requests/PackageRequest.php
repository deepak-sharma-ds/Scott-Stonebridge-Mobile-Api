<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PackageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title'        => 'required|string|max:255',
            'description'  => 'nullable|string',
            'price'        => ['required', 'numeric', 'regex:/^\d+(\.\d{1,2})?$/'],
            'currency'     => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'shopify_tag'  => [
                'nullable',
                'string',
                Rule::unique('packages', 'shopify_tag')->ignore($this->route('package')),
            ],
            'cover_image'  => ['nullable', 'image', 'max:2048'],
        ];
    }

    /**
     * Get custom error messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Please provide a title.',
            'price.required' => 'Please provide a price.',
            'price.numeric'  => 'The price must be a numeric value.',
            'price.regex'    => 'The price must be a valid monetary amount (up to 2 decimal places).',
            'currency.required' => 'Please provide a currency.',
            'currency.string' => 'The currency must be a string.',
            'currency.regex'  => 'The currency must be a valid 3-letter ISO code (e.g., USD, GBP).',
            'shopify_tag.string' => 'The Shopify tag must be a string.',
            'shopify_tag.unique' => 'This tag is already used by another package.',
            'cover_image.image' => 'The cover image must be an image file.',
            'cover_image.max'   => 'The cover image may not be greater than 2MB.',
        ];
    }


    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation()
    {
        if ($this->has('shopify_tag')) {
            $this->merge([
                'shopify_tag' => Str::slug($this->shopify_tag, '-'),
            ]);
        }
    }
}
