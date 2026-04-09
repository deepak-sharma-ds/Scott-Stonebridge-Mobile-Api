<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class CustomerEntitlementEmailsRequest extends FormRequest
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
            'emails' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'emails.required' => 'Please provide at least one email address.',
            'emails.string' => 'The emails field must be a valid text value.',
            'emails.max' => 'The emails field may not be greater than 5000 characters.',
        ];
    }

    /**
     * Validate parsed email entries after the base rules run.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $emails = $this->emails();

            if (empty($emails)) {
                $validator->errors()->add('emails', 'Please provide at least one valid email address.');
                return;
            }

            foreach ($emails as $email) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $validator->errors()->add('emails', "The email address [{$email}] is not valid.");
                }
            }
        });
    }

    /**
     * Return unique, normalized email addresses from the textarea input.
     *
     * @return array<int, string>
     */
    public function emails(): array
    {
        $value = (string) $this->input('emails', '');
        $entries = preg_split('/[\r\n,;]+/', $value) ?: [];

        $emails = array_map(
            static fn(string $email): string => Str::lower(trim($email)),
            $entries
        );

        $emails = array_filter($emails, static fn(string $email): bool => $email !== '');

        return array_values(array_unique($emails));
    }
}
