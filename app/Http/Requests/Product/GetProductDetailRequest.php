<?php

namespace App\Http\Requests\Product;

use App\Http\Requests\BaseApiRequest;

class GetProductDetailRequest extends BaseApiRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'handle' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'handle.required' => 'The product handle is required.',
            'handle.regex' => 'The product handle must contain only lowercase letters, numbers, and hyphens.',
        ]);
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        
        // Get handle from route parameter if not in request
        if (!$this->has('handle') && $this->route('handle')) {
            $this->merge([
                'handle' => $this->route('handle'),
            ]);
        }
    }
}
