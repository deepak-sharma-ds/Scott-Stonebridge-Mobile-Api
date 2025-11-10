<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AudioRequest extends FormRequest
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
        // Check if we're updating (PUT/PATCH) or creating (POST)
        $isUpdate = in_array($this->method(), ['PUT', 'PATCH']);

        return [
            'package_id' => 'required|exists:packages,id',
            'title' => 'required|string|max:255',
            'file' => [
                $isUpdate ? 'nullable' : 'required',
                'file',
                'mimes:mp3,wav'
            ],
            'duration_seconds' => 'nullable|integer',
        ];
    }

    /**
     * Get custom error messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'package_id.required' => 'Please select a package.',
            'package_id.exists'   => 'Selected package does not exist.',
            'title.required'      => 'Please provide a title.',
            'file.required'       => 'Please upload an audio file.',
            'file.mimes'          => 'Audio file must be MP3 or WAV format.',
        ];
    }
}
