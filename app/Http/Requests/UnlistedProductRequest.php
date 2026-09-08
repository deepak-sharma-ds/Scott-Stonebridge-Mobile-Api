<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UnlistedProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            'header_image' => ['nullable', 'image', 'max:15360'],
            'email_content' => ['nullable', 'string'],
            'email_footer' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string,string>
     */
    public function messages(): array
    {
        return [
            'header_image.max' => 'The header image must not be greater than 15MB.',
        ];
    }
}
