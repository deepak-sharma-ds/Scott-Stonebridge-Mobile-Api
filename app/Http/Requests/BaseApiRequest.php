<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

class BaseApiRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Check ALL inputs for <script> or ANY HTML.
     */
    protected function prepareForValidation()
    {
        foreach ($this->all() as $key => $value) {

            if (is_string($value)) {
                // Detect <script> tag
                if (preg_match('/<\s*script/mi', $value)) {
                    $this->throwXssError($key, 'Script tags are not allowed.');
                }

                // Detect ANY HTML tags
                // if ($this->containsHtml($value)) {
                //     $this->throwXssError($key, 'HTML tags are not allowed.');
                // }
            }
        }
    }

    /**
     * Helper - detects ANY html tag
     */
    private function containsHtml($value)
    {
        // Detect <tag>, </tag>, <img>, <a>, etc.
        return $value !== strip_tags($value);
    }

    /**
     * Helper - trigger validation error manually
     */
    private function throwXssError($field, $message)
    {
        $errors = [$field => [$message]];

        throw new ValidationException(
            null,
            response()->json([
                'success' => false,
                'message' => 'No script or HTML tags are allowed in input fields.',
                'data' => [],
                'meta' => [
                    'error_code' => 'VALIDATION_ERROR',
                    'errors' => $errors,
                ],
            ], 422)
        );
    }

    /**
     * Handle validation error formatting
     * Returns standardized API response format
     */
    protected function failedValidation(Validator $validator)
    {
        throw new ValidationException(
            $validator,
            response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'data' => [],
                'meta' => [
                    'error_code' => 'VALIDATION_ERROR',
                    'errors' => $validator->errors(),
                ],
            ], 422)
        );
    }

    /**
     * Common validation rules for pagination
     */
    protected function paginationRules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string'],
        ];
    }

    /**
     * Common validation rules for Shopify IDs
     */
    protected function shopifyIdRules(): array
    {
        return ['required', 'string', 'regex:/^gid:\/\/shopify\//'];
    }

    /**
     * Common validation messages
     */
    public function messages(): array
    {
        return [
            'limit.integer' => 'The limit must be a valid integer.',
            'limit.min' => 'The limit must be at least 1.',
            'limit.max' => 'The limit cannot exceed 100.',
            'cursor.string' => 'The cursor must be a valid string.',
            'required' => 'The :attribute field is required.',
            'string' => 'The :attribute must be a string.',
            'integer' => 'The :attribute must be an integer.',
            'email' => 'The :attribute must be a valid email address.',
        ];
    }
}
