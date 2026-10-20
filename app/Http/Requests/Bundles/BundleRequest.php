<?php

namespace App\Http\Requests\Bundles;

use App\Services\Bundles\BundleImageService;
use Illuminate\Foundation\Http\FormRequest;

class BundleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage bundle') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title'      => trim((string) $this->input('title')),
            'components' => array_values((array) $this->input('components', [])),
        ]);
    }

    public function rules(): array
    {
        $max = config('bundles.limits');

        return [
            'title'                     => ['required', 'string', 'max:255'],
            'description'               => ['nullable', 'string', 'max:20000'],
            // Images: new files + final order ("id:12" = existing image, "new:0" = images[0]).
            // images_present tells the server the image list was sent (so an empty list removes all).
            'images_present'            => ['nullable', 'boolean'],
            'images'                    => ['nullable', 'array', 'max:' . BundleImageService::MAX_IMAGES],
            'images.*'                  => ['image', 'mimes:jpeg,jpg,png,gif,webp', 'max:10240'],
            'image_order'               => ['nullable', 'array', 'max:' . BundleImageService::MAX_IMAGES],
            'image_order.*'             => ['string', 'regex:/^(id|new):\d+$/'],
            'price'                     => ['required', 'numeric', 'min:0.01', 'max:99999'],
            'components'                => ['required', 'array', 'min:1', 'max:' . $max['max_components']],
            'components.*.variant_id'   => ['required', 'string', 'starts_with:gid://shopify/ProductVariant/', 'distinct'],
            'components.*.quantity'     => ['required', 'integer', 'min:1', 'max:' . $max['max_quantity']],
            'action'                    => ['nullable', 'in:save,activate'],
            'offer_on_product_page'     => ['nullable', 'boolean'],
            'offer_in_cart'             => ['nullable', 'boolean'],
            'offer_heading'             => ['nullable', 'string', 'max:150'],
            'offer_include_components'  => ['nullable', 'boolean'],
            'offer_products'            => ['nullable', 'array', 'max:50'],
            'offer_products.*'          => ['string', 'starts_with:gid://shopify/Product/'],
        ];
    }

    public function messages(): array
    {
        return [
            'images.*.image' => 'Each bundle image must be a JPEG, PNG, GIF or WebP file.',
            'images.*.mimes' => 'Each bundle image must be a JPEG, PNG, GIF or WebP file.',
            'images.*.max'   => 'Each bundle image must be 10 MB or smaller.',
            'images.max'     => 'A bundle can have up to ' . BundleImageService::MAX_IMAGES . ' images.',
            'components.required'              => 'Add at least one component product.',
            'components.*.variant_id.distinct' => 'The same product variant was added twice. Increase its quantity instead.',
        ];
    }

    public function offerSettings(): array
    {
        return [
            'offer_on_product_page' => $this->boolean('offer_on_product_page'),
            'offer_in_cart'         => $this->boolean('offer_in_cart'),
            'offer_include_components' => $this->boolean('offer_include_components'),
            'offer_heading'         => $this->filled('offer_heading') ? trim($this->input('offer_heading')) : null,
        ];
    }

    /**
     * @return array{present: bool, order: array, files: array}
     */
    public function imageInput(): array
    {
        return [
            'present' => $this->boolean('images_present'),
            'order'   => array_values((array) $this->input('image_order', [])),
            'files'   => array_values(array_filter((array) $this->file('images', []))),
        ];
    }

    public function offerProductIds(): array
    {
        return array_values(array_unique((array) $this->validated('offer_products', [])));
    }

    /**
     * Only the trusted fields; display fields posted with each row are ignored.
     */
    public function componentRows(): array
    {
        return array_map(fn ($row) => [
            'variant_id' => $row['variant_id'],
            'quantity'   => (int) $row['quantity'],
        ], $this->validated('components'));
    }
}
