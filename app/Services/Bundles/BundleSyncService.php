<?php

namespace App\Services\Bundles;

use App\Models\Bundles\Bundle;
use App\Models\Bundles\BundleImage;
use App\Models\Bundles\BundleSyncLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Pushes predefined bundles to Shopify as native fixed bundles:
 *
 *   parent product (tag: zymox-bundle, no SKU)
 *     └─ default variant ── productVariantComponents ──► component variants × qty
 *
 * Shopify expands the parent into its component variants at checkout, so the
 * order carries the component SKUs the warehouse needs for fulfillment.
 *
 * Every step is idempotent, so a failed push can simply be retried.
 */
class BundleSyncService
{
    protected array $steps = [];

    public function __construct(
        protected ShopifyAdminClient $client,
        protected BundleOfferService $offers,
    ) {
    }

    /**
     * Create or update the bundle in Shopify (product, components, price, metafield, status).
     */
    public function push(Bundle $bundle, string $operation = 'push'): void
    {
        $this->run($bundle, $operation, function () use ($bundle) {
            $bundle->loadMissing('components');

            if (! $bundle->shopify_product_id) {
                $this->createProduct($bundle);
            } else {
                $this->updateProduct($bundle);
            }

            $this->syncImages($bundle);
            $this->syncComponents($bundle);
            $this->updateParentVariant($bundle);
            $this->writeMetafield($bundle);
            $this->applyStatus($bundle);
            $this->step($this->offers->sync($bundle));
        });
    }

    /**
     * Activate (ACTIVE + publish to Online Store) or pause (DRAFT) a bundle.
     */
    public function changeStatus(Bundle $bundle, string $status, bool $fullPush = false): void
    {
        $previous = $bundle->status;
        $operation = $status === Bundle::STATUS_ACTIVE ? 'activate' : 'pause';
        $bundle->update(['status' => $status]);

        try {
            if ($fullPush || ! $bundle->shopify_product_id) {
                $this->push($bundle, $operation);
            } else {
                $this->run($bundle, $operation, function () use ($bundle) {
                    $this->applyStatus($bundle);
                    $this->step($this->offers->sync($bundle));
                });
            }
        } catch (Throwable $e) {
            $bundle->update(['status' => $previous]);

            throw $e;
        }
    }

    /**
     * Delete the bundle product from Shopify. Past orders keep their line items.
     */
    public function deleteFromShopify(Bundle $bundle): void
    {
        if (! $bundle->shopify_product_id) {
            return;
        }

        $this->run($bundle, 'delete', function () use ($bundle) {
            // Remove the offer from product pages before the bundle product disappears.
            $this->step($this->offers->sync($bundle, removed: true));

            $this->client->mutate('productDelete', <<<'GQL'
                mutation($input: ProductDeleteInput!) {
                    productDelete(input: $input) { deletedProductId userErrors { field message } }
                }
            GQL, ['input' => ['id' => $bundle->shopify_product_id]]);

            $this->step('Deleted bundle product from Shopify');
        }, markSynced: false);
    }

    // ------------------------------------------------------------------
    // Steps
    // ------------------------------------------------------------------

    protected function createProduct(Bundle $bundle): void
    {
        $result = $this->client->mutate('productCreate', <<<'GQL'
            mutation($product: ProductCreateInput!) {
                productCreate(product: $product) {
                    product { id handle variants(first: 1) { nodes { id } } }
                    userErrors { field message }
                }
            }
        GQL, ['product' => array_filter([
            'title'           => $bundle->title,
            'descriptionHtml' => $bundle->description,
            'status'          => 'DRAFT',
            'productType'     => config('bundles.product_type'),
            'tags'            => [config('bundles.product_tag')],
        ], fn ($v) => $v !== null)]);

        // Persist IDs straight away so a retry never creates a duplicate product.
        $bundle->update([
            'shopify_product_id' => $result['product']['id'],
            'shopify_variant_id' => $result['product']['variants']['nodes'][0]['id'] ?? null,
            'shopify_handle'     => $result['product']['handle'] ?? null,
        ]);

        $this->step('Created bundle product ' . $result['product']['id']);
    }

    protected function updateProduct(Bundle $bundle): void
    {
        $result = $this->client->mutate('productUpdate', <<<'GQL'
            mutation($product: ProductUpdateInput!) {
                productUpdate(product: $product) {
                    product { id handle variants(first: 1) { nodes { id } } }
                    userErrors { field message }
                }
            }
        GQL, ['product' => array_filter([
            'id'              => $bundle->shopify_product_id,
            'title'           => $bundle->title,
            'descriptionHtml' => $bundle->description,
        ], fn ($v) => $v !== null)]);

        $bundle->update([
            'shopify_variant_id' => $bundle->shopify_variant_id ?: ($result['product']['variants']['nodes'][0]['id'] ?? null),
            'shopify_handle'     => $result['product']['handle'] ?? $bundle->shopify_handle,
        ]);

        $this->step('Updated bundle product details');
    }

    /**
     * Keep the bundle product's images in Shopify in line with the app:
     *  - uploaded bundle images are sent to Shopify (staged upload), in the chosen order, first = main image;
     *  - images removed in the app are deleted from Shopify;
     *  - with no uploads, component images are added once if the product has no images at all.
     * Only media created by the app is ever changed; images added in the Shopify admin are left alone.
     * An image failure never fails the sync.
     */
    protected function syncImages(Bundle $bundle): void
    {
        $bundle->load('images');

        try {
            if ($pending = $bundle->pending_media_deletes) {
                $this->deleteMedia($bundle, $pending);
                $bundle->forceFill(['pending_media_deletes' => []])->saveQuietly();
                $this->step('Removed ' . count($pending) . ' deleted image(s) from Shopify');
            }

            if ($bundle->images->isEmpty()) {
                $this->addComponentImages($bundle);

                return;
            }

            // Uploaded images replace the automatic component images.
            if ($auto = $bundle->auto_media_ids) {
                $this->deleteMedia($bundle, $auto);
                $bundle->forceFill(['auto_media_ids' => []])->saveQuietly();
            }

            $media = $this->productMedia($bundle);
            $present = array_column($media, 'id');

            // New uploads, plus any whose Shopify copy was deleted in the Shopify admin.
            $new = $bundle->images
                ->filter(fn ($image) => ! $image->shopify_media_id || ! in_array($image->shopify_media_id, $present, true))
                ->values();

            if ($new->isNotEmpty()) {
                $this->client->mutate('productUpdate', <<<'GQL'
                    mutation($product: ProductUpdateInput!, $media: [CreateMediaInput!]) {
                        productUpdate(product: $product, media: $media) { product { id } userErrors { field message } }
                    }
                GQL, [
                    'product' => ['id' => $bundle->shopify_product_id],
                    'media'   => $new->map(fn ($image) => [
                        'originalSource'   => $this->stageUpload($image),
                        'mediaContentType' => 'IMAGE',
                        'alt'              => $bundle->title,
                    ])->all(),
                ]);

                // New media is appended in the order sent: match it to our images.
                $media = $this->productMedia($bundle);
                $created = array_values(array_diff(array_column($media, 'id'), $present));
                foreach ($new as $index => $image) {
                    if (isset($created[$index])) {
                        $image->update(['shopify_media_id' => $created[$index], 'shopify_url' => null]);
                    }
                }

                $this->step("Uploaded {$new->count()} bundle image(s) to Shopify");
            }

            // Our images first, in the order chosen in the app.
            $images = $bundle->images()->get();
            $order = array_column($media, 'id');
            $moves = [];
            foreach ($images->values() as $position => $image) {
                if ($image->shopify_media_id) {
                    $moves[] = ['id' => $image->shopify_media_id, 'newPosition' => (string) $position];
                }
            }
            $inPlace = collect($moves)->every(fn ($m) => array_search($m['id'], $order, true) === (int) $m['newPosition']);

            if ($moves && ! $inPlace) {
                $data = $this->client->query(<<<'GQL'
                    mutation($id: ID!, $moves: [MoveInput!]!) {
                        productReorderMedia(id: $id, moves: $moves) { mediaUserErrors { field message } }
                    }
                GQL, ['id' => $bundle->shopify_product_id, 'moves' => $moves]);
                $this->throwMediaErrors('productReorderMedia', $data);
                $this->step('Bundle image order updated');
            }

            // Remember Shopify's CDN URLs for previews (available once Shopify has processed the image).
            $urls = array_column($media, 'url', 'id');
            foreach ($images as $image) {
                $url = $urls[$image->shopify_media_id] ?? null;
                if ($url && $url !== $image->shopify_url) {
                    $image->update(['shopify_url' => $url]);
                }
            }
        } catch (Throwable $e) {
            $this->step('Images not fully synced: ' . $e->getMessage());
        }
    }

    /**
     * No uploaded images: give the product the component images, once, if it has no images at all.
     */
    protected function addComponentImages(Bundle $bundle): void
    {
        $urls = $bundle->components->pluck('image_url')->filter()->unique()->take(10)->values();
        $media = $this->productMedia($bundle);

        if ($urls->isEmpty() || $media) {
            return;
        }

        $this->client->mutate('productUpdate', <<<'GQL'
            mutation($product: ProductUpdateInput!, $media: [CreateMediaInput!]) {
                productUpdate(product: $product, media: $media) { product { id } userErrors { field message } }
            }
        GQL, [
            'product' => ['id' => $bundle->shopify_product_id],
            'media'   => $urls->map(fn ($url) => ['originalSource' => $url, 'mediaContentType' => 'IMAGE', 'alt' => $bundle->title])->all(),
        ]);

        $bundle->forceFill(['auto_media_ids' => array_column($this->productMedia($bundle), 'id')])->saveQuietly();
        $this->step("Added {$urls->count()} component image(s) to the bundle product");
    }

    /**
     * @return array<int, array{id: string, url: ?string}> in Shopify's display order
     */
    protected function productMedia(Bundle $bundle): array
    {
        $data = $this->client->query(<<<'GQL'
            query($id: ID!) {
                product(id: $id) {
                    media(first: 100) { nodes { id ... on MediaImage { image { url } } } }
                }
            }
        GQL, ['id' => $bundle->shopify_product_id]);

        return array_map(fn ($node) => [
            'id'  => $node['id'],
            'url' => $node['image']['url'] ?? null,
        ], $data['product']['media']['nodes'] ?? []);
    }

    protected function deleteMedia(Bundle $bundle, array $mediaIds): void
    {
        $present = array_column($this->productMedia($bundle), 'id');
        $mediaIds = array_values(array_intersect($mediaIds, $present));
        if (! $mediaIds) {
            return;
        }

        $data = $this->client->query(<<<'GQL'
            mutation($productId: ID!, $mediaIds: [ID!]!) {
                productDeleteMedia(productId: $productId, mediaIds: $mediaIds) {
                    deletedMediaIds
                    mediaUserErrors { field message }
                }
            }
        GQL, ['productId' => $bundle->shopify_product_id, 'mediaIds' => $mediaIds]);

        $this->throwMediaErrors('productDeleteMedia', $data);
    }

    /**
     * Upload a stored image to Shopify's staging storage and return the resourceUrl for CreateMediaInput.
     * Works without a public URL for the Laravel server.
     */
    protected function stageUpload(BundleImage $image): string
    {
        $path = Storage::disk('public')->path($image->path);
        if (! is_file($path)) {
            throw new ShopifyApiException("Image file is missing on the server: {$image->path}");
        }

        $filename = basename($path);
        $result = $this->client->mutate('stagedUploadsCreate', <<<'GQL'
            mutation($input: [StagedUploadInput!]!) {
                stagedUploadsCreate(input: $input) {
                    stagedTargets { url resourceUrl parameters { name value } }
                    userErrors { field message }
                }
            }
        GQL, ['input' => [[
            'resource'   => 'IMAGE',
            'filename'   => $filename,
            'mimeType'   => mime_content_type($path) ?: 'image/jpeg',
            'httpMethod' => 'POST',
            'fileSize'   => (string) filesize($path),
        ]]]);

        $target = $result['stagedTargets'][0] ?? null;
        if (! $target) {
            throw new ShopifyApiException('Shopify did not return an upload target.');
        }

        // Signed fields must come before the file (Laravel sends fields first, then attachments).
        $fields = array_column($target['parameters'], 'value', 'name');
        $response = Http::timeout(120)
            ->attach('file', file_get_contents($path), $filename)
            ->post($target['url'], $fields);

        if (! $response->successful()) {
            throw new ShopifyApiException("Image upload to Shopify failed [{$response->status()}].");
        }

        return $target['resourceUrl'];
    }

    protected function throwMediaErrors(string $operation, array $data): void
    {
        $errors = $data[$operation]['mediaUserErrors'] ?? [];
        if ($errors) {
            throw new ShopifyApiException("{$operation}: " . collect($errors)->pluck('message')->implode('; '));
        }
    }

    /**
     * Diff the desired components against what Shopify has and apply the changes.
     */
    protected function syncComponents(Bundle $bundle): void
    {
        if (! $bundle->shopify_variant_id) {
            throw new ShopifyApiException('Bundle product has no variant to attach components to.');
        }

        $data = $this->client->query(<<<'GQL'
            query($id: ID!) {
                productVariant(id: $id) {
                    productVariantComponents(first: 50) {
                        nodes { quantity productVariant { id } }
                    }
                }
            }
        GQL, ['id' => $bundle->shopify_variant_id]);

        $current = collect($data['productVariant']['productVariantComponents']['nodes'] ?? [])
            ->mapWithKeys(fn ($n) => [$n['productVariant']['id'] => (int) $n['quantity']]);

        $desired = $bundle->components->mapWithKeys(fn ($c) => [$c->shopify_variant_id => (int) $c->quantity]);

        $create = $desired->diffKeys($current)->map(fn ($qty, $id) => ['id' => $id, 'quantity' => $qty])->values()->all();
        $update = $desired->intersectByKeys($current)
            ->filter(fn ($qty, $id) => $current[$id] !== $qty)
            ->map(fn ($qty, $id) => ['id' => $id, 'quantity' => $qty])->values()->all();
        $remove = $current->diffKeys($desired)->keys()->values()->all();

        if (! $create && ! $update && ! $remove) {
            $this->step('Components already up to date');

            return;
        }

        $this->client->mutate('productVariantRelationshipBulkUpdate', <<<'GQL'
            mutation($input: [ProductVariantRelationshipUpdateInput!]!) {
                productVariantRelationshipBulkUpdate(input: $input) {
                    parentProductVariants { id }
                    userErrors { code field message }
                }
            }
        GQL, ['input' => [array_filter([
            'parentProductVariantId'              => $bundle->shopify_variant_id,
            'productVariantRelationshipsToCreate' => $create,
            'productVariantRelationshipsToUpdate' => $update,
            'productVariantRelationshipsToRemove' => $remove,
        ])]]);

        $this->step(sprintf('Components synced (+%d, ~%d, -%d)', count($create), count($update), count($remove)));
    }

    protected function updateParentVariant(Bundle $bundle): void
    {
        $compareAt = $bundle->savings_amount > 0 ? number_format((float) $bundle->compare_at_price, 2, '.', '') : null;

        $this->client->mutate('productVariantsBulkUpdate', <<<'GQL'
            mutation($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
                productVariantsBulkUpdate(productId: $productId, variants: $variants) {
                    productVariants { id price compareAtPrice }
                    userErrors { field message }
                }
            }
        GQL, [
            'productId' => $bundle->shopify_product_id,
            'variants'  => [[
                'id'                 => $bundle->shopify_variant_id,
                'price'              => number_format((float) $bundle->price, 2, '.', ''),
                'compareAtPrice'     => $compareAt,
                // The parent can only be sold together with its components.
                'requiresComponents' => true,
            ]],
        ]);

        $this->step("Price set to {$bundle->price}" . ($compareAt ? " (compare at {$compareAt})" : ''));
    }

    /**
     * Component details for the storefront "What's included" theme block.
     */
    protected function writeMetafield(Bundle $bundle): void
    {
        $value = [
            'components' => $bundle->components->map(fn ($c) => [
                'title'      => $c->product_title,
                'variant'    => $c->variant_title !== 'Default Title' ? $c->variant_title : null,
                // Numeric ID, compared with cart line item variant_id in Liquid
                'variant_id' => (int) Gid::toId($c->shopify_variant_id),
                'sku'      => $c->sku,
                'quantity' => $c->quantity,
                'price'    => (float) $c->unit_price,
                'image'    => $c->image_url,
                'handle'   => $c->product_handle,
            ])->values()->all(),
            'components_total' => (float) $bundle->compare_at_price,
            'bundle_price'     => (float) $bundle->price,
            'savings_amount'   => $bundle->savings_amount,
            'savings_percent'  => $bundle->savings_percent,
            'offer'            => [
                'product_page' => (bool) $bundle->offer_on_product_page,
                'cart'         => (bool) $bundle->offer_in_cart,
                'heading'      => $bundle->offer_heading,
            ],
        ];

        $this->client->mutate('metafieldsSet', <<<'GQL'
            mutation($metafields: [MetafieldsSetInput!]!) {
                metafieldsSet(metafields: $metafields) {
                    metafields { id }
                    userErrors { field message }
                }
            }
        GQL, ['metafields' => [[
            'ownerId'   => $bundle->shopify_product_id,
            'namespace' => config('bundles.metafield_namespace'),
            'key'       => config('bundles.metafield_key'),
            'type'      => 'json',
            'value'     => json_encode($value),
        ]]]);

        $this->step('Storefront metafield updated');
    }

    protected function applyStatus(Bundle $bundle): void
    {
        $shopifyStatus = $bundle->isActive() ? 'ACTIVE' : 'DRAFT';

        $this->client->mutate('productUpdate', <<<'GQL'
            mutation($product: ProductUpdateInput!) {
                productUpdate(product: $product) { product { id status } userErrors { field message } }
            }
        GQL, ['product' => ['id' => $bundle->shopify_product_id, 'status' => $shopifyStatus]]);

        $this->step("Shopify status set to {$shopifyStatus}");

        if ($bundle->isActive()) {
            $this->publishToOnlineStore($bundle);
        }
    }

    protected function publishToOnlineStore(Bundle $bundle): void
    {
        $publicationId = $this->onlineStorePublicationId();
        if (! $publicationId) {
            $this->step('Publish skipped: Online Store publication not found (set BUNDLE_PUBLICATION_ID)');

            return;
        }

        $this->client->mutate('publishablePublish', <<<'GQL'
            mutation($id: ID!, $input: [PublicationInput!]!) {
                publishablePublish(id: $id, input: $input) { userErrors { field message } }
            }
        GQL, ['id' => $bundle->shopify_product_id, 'input' => [['publicationId' => $publicationId]]]);

        $this->step('Published to Online Store');
    }

    public function onlineStorePublicationId(): ?string
    {
        if ($configured = config('bundles.publication_id')) {
            return $configured;
        }

        $cacheKey = 'bundles.publication.' . md5($this->client->credentials()->shopDomain());

        return Cache::remember($cacheKey, now()->addDay(), function () {
            $data = $this->client->query('{ publications(first: 25) { nodes { id name } } }');

            return collect($data['publications']['nodes'] ?? [])
                ->firstWhere('name', config('bundles.publication_name'))['id'] ?? null;
        });
    }

    // ------------------------------------------------------------------
    // Plumbing
    // ------------------------------------------------------------------

    protected function run(Bundle $bundle, string $operation, callable $callback, bool $markSynced = true): void
    {
        $this->steps = [];
        $started = microtime(true);

        try {
            $callback();

            if ($markSynced) {
                $bundle->update([
                    'sync_status'     => Bundle::SYNC_SYNCED,
                    'last_synced_at'  => now(),
                    'last_sync_error' => null,
                ]);
            }

            $this->log($bundle, $operation, 'success', null, $started);
        } catch (Throwable $e) {
            $bundle->update([
                'sync_status'     => Bundle::SYNC_FAILED,
                'last_sync_error' => $e->getMessage(),
            ]);

            $this->log($bundle, $operation, 'failed', $e->getMessage(), $started);

            throw $e;
        }
    }

    protected function step(string $message): void
    {
        $this->steps[] = $message;
    }

    protected function log(Bundle $bundle, string $operation, string $status, ?string $error, float $started): void
    {
        BundleSyncLog::create([
            'bundle_id'   => $bundle->id,
            'operation'   => $operation,
            'status'      => $status,
            'details'     => ['steps' => $this->steps],
            'error'       => $error,
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            'user_id'     => auth()->id(),
        ]);
    }
}
