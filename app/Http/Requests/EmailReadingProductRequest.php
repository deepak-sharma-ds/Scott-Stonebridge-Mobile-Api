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
        $product = $this->route('email_reading_product');
        $productId = $product?->id;

        // Required only while automation is on; a stored header image already
        // satisfies the requirement on edit.
        $whenAutomated = Rule::requiredIf(fn () => $this->boolean('is_automation_enabled'));
        $headerImageRequired = Rule::requiredIf(
            fn () => $this->boolean('is_automation_enabled') && empty($product?->header_image)
        );

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
            'email_subject' => [$whenAutomated, 'nullable', 'string', 'max:255'],
            'email_view' => ['nullable', 'string', 'max:255'],
            'header_image' => [$headerImageRequired, 'nullable', 'image', 'max:15360'],
            'email_content' => [$whenAutomated, 'nullable', 'string'],
            'email_footer' => [$whenAutomated, 'nullable', 'string'],
            'model' => ['nullable', 'string', 'max:255'],
            'max_tokens' => ['nullable', 'integer', 'min:1', 'max:8000'],
            'is_active' => ['nullable', 'boolean'],
            'is_automation_enabled' => ['nullable', 'boolean'],
            'prompt_template' => [$whenAutomated, 'nullable', 'string'],
            'formatting_contract' => ['nullable', 'string'],
            // An active product with zero questions is a legitimate final
            // state (some readings need no personalization) — not every
            // reading product requires a question schema, so this is
            // intentionally not `required`/`min:1`.
            'questions_schema' => ['nullable', 'array'],
            'questions_schema.*.key' => ['required', 'string', 'max:255'],
            'questions_schema.*.label' => ['required', 'string', 'max:1000'],
            'questions_schema.*.type' => ['nullable', 'string', Rule::in(['text', 'textarea', 'image'])],
            'questions_schema.*.required' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Normalise the schema repeater (drop blank rows, cast flags, default a
     * missing type to text) and derive a slug from the name when none was
     * supplied.
     */
    protected function prepareForValidation(): void
    {
        $schema = collect((array) $this->input('questions_schema', []))
            ->filter(fn ($row) => is_array($row) && trim((string) ($row['key'] ?? '')) !== '')
            ->map(fn ($row) => [
                'key' => trim((string) $row['key']),
                'label' => trim((string) ($row['label'] ?? '')),
                'type' => in_array($row['type'] ?? null, ['text', 'textarea', 'image'], true) ? $row['type'] : 'text',
                'required' => filter_var($row['required'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ])
            ->values()
            ->all();

        $this->merge([
            'questions_schema' => $schema,
            'slug' => Str::slug($this->input('slug') ?: $this->input('name', '')),
            'is_active' => filter_var($this->input('is_active', false), FILTER_VALIDATE_BOOLEAN),
            'is_automation_enabled' => filter_var($this->input('is_automation_enabled', false), FILTER_VALIDATE_BOOLEAN),
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
            'email_subject.required' => 'The email subject is required when automation is enabled.',
            'header_image.required' => 'The header banner image is required when automation is enabled.',
            'email_content.required' => 'The email content is required when automation is enabled.',
            'email_footer.required' => 'The email footer is required when automation is enabled.',
            'prompt_template.required' => 'The prompt template is required when automation is enabled.',
            'header_image.max' => 'The header image must not be greater than 15MB.',
        ];
    }
}
