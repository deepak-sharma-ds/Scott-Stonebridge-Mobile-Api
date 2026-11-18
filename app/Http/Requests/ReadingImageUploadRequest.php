<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReadingImageUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Exactly one JPG/PNG. The service re-checks the real image type and size.
     *
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'image' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png',
                'max:'.(int) config('email_reading.image_upload.max_kb', 8192),
            ],
            'product_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'image.required' => 'Please choose one image to upload.',
            'image.file' => 'Please upload a single image file.',
            'image.mimes' => 'The image must be a JPG or PNG file.',
            'image.max' => 'The image is too large. Please choose one under '
                .round((int) config('email_reading.image_upload.max_kb', 8192) / 1024, 1).' MB.',
            'image.uploaded' => 'The image could not be uploaded. Please try again.',
        ];
    }
}
