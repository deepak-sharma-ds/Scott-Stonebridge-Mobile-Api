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
                'status' => 422,
                'message' => 'No script or HTML tags are allowed in input fields.',
                'error' => $errors,
            ], 422)
        );
    }

    /**
     * Handle validation error formatting
     */
    protected function failedValidation(Validator $validator)
    {
        throw new ValidationException(
            $validator,
            response()->json([
                'status' => 422,
                'message' => 'Validation failed.',
                'error' => $validator->errors(),
            ], 422)
        );
    }
}
