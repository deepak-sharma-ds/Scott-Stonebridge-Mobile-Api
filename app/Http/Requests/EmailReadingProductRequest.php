<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EmailReadingProductRequest extends FormRequest
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
        $productId = $this->route('email_reading_product')?->id;

        return [
            'shopify_product_id' => [
                'required', 'integer',
                Rule::unique('email_reading_products', 'shopify_product_id')->ignore($productId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:255',
                Rule::unique('email_reading_products', 'slug')->ignore($productId),
            ],
            'email_subject' => ['required', 'string', 'max:255'],
            'email_view' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'max_tokens' => ['nullable', 'integer', 'min:1', 'max:8000'],
            'is_active' => ['nullable', 'boolean'],
            'prompt_template' => ['required', 'string'],
            'questions_schema' => ['required', 'array', 'min:1'],
            'questions_schema.*.key' => ['required', 'string', 'max:255'],
            'questions_schema.*.label' => ['required', 'string', 'max:1000'],
            'questions_schema.*.required' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Normalise the schema repeater (drop blank rows, cast flags) and derive a
     * slug from the name when none was supplied.
     */
    protected function prepareForValidation(): void
    {
        $schema = collect((array) $this->input('questions_schema', []))
            ->filter(fn ($row) => is_array($row) && trim((string) ($row['key'] ?? '')) !== '')
            ->map(fn ($row) => [
                'key' => trim((string) $row['key']),
                'label' => trim((string) ($row['label'] ?? '')),
                'required' => filter_var($row['required'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ])
            ->values()
            ->all();

        $this->merge([
            'questions_schema' => $schema,
            'slug' => Str::slug($this->input('slug') ?: $this->input('name', '')),
            'is_active' => filter_var($this->input('is_active', false), FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    /**
     * @return array<string,string>
     */
    public function messages(): array
    {
        return [
            'shopify_product_id.unique' => 'A reading product with this Shopify product ID already exists.',
            'slug.unique' => 'This slug is already in use.',
            'questions_schema.required' => 'Add at least one question slot.',
            'questions_schema.min' => 'Add at least one question slot.',
        ];
    }
}
