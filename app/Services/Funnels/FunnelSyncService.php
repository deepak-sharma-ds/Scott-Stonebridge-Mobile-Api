<?php

namespace App\Services\Funnels;

use App\Models\Bundles\BundleSyncLog;
use App\Models\Funnels\Funnel;
use App\Models\Funnels\FunnelItem;
use App\Services\Bundles\Gid;
use App\Services\Bundles\ShopifyAdminClient;
use App\Services\Bundles\ShopifyApiException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Pushes funnels to Shopify:
 *  1. The discount (config bundles.discount_mode):
 *     - function (default): one automatic app discount per funnel, run by the app's Discount Function
 *       (extensions/bundle-discount). Its metafield holds the funnel's products and discounts; the
 *       function discounts the bundle lines only when the whole bundle is in the cart.
 *     - native: Shopify basic automatic discounts, one per discount group ("minimum quantity" rule).
 *     Each bundle line shows a label like "BUNDLE OFFER 10% OFF" in the cart and at checkout.
 *  2. The storefront config (all live funnels) as an app-owned metafield, read by the theme blocks.
 */
class FunnelSyncService
{
    public const CONFIG_NAMESPACE = 'zymox_funnels';
    public const CONFIG_KEY = 'config';
    public const FUNCTION_CONFIG_KEY = 'function-config';

    protected array $steps = [];

    public function __construct(protected ShopifyAdminClient $client)
    {
    }

    /**
     * Recreate the funnel's discounts and republish the storefront config.
     */
    public function sync(Funnel $funnel, string $operation = 'sync'): void
    {
        $this->run($funnel, $operation, function () use ($funnel) {
            $funnel->loadMissing('items');
            $this->deleteDiscounts($funnel);

            if ($funnel->isActive()) {
                $this->createDiscounts($funnel);
            }

            $this->publishConfig();
        });
    }

    /**
     * Remove the funnel's discounts (on delete) and republish without it.
     */
    public function remove(Funnel $funnel): void
    {
        $this->run($funnel, 'delete', function () use ($funnel) {
            $this->deleteDiscounts($funnel);
            $this->publishConfig(excludeId: $funnel->id);
        }, markSynced: false);
    }

    /**
     * Group items by identical discount; each group becomes one automatic discount.
     *
     * @return array<int, array{type: string, value: float, items: array}>
     */
    public function discountGroups(Funnel $funnel): array
    {
        // Basic discounts take a fixed amount off each unit: use the per-unit share of the line amount.
        $perUnit = fn ($i) => $i->discount_type === FunnelItem::FIXED
            ? round((float) $i->discount_value / max(1, (int) $i->quantity), 2)
            : (float) $i->discount_value;

        return $funnel->items
            ->reject(fn ($i) => $i->discount_type === FunnelItem::NONE || (float) $i->discount_value <= 0)
            ->groupBy(fn ($i) => $i->discount_type . ':' . number_format($perUnit($i), 2, '.', ''))
            ->map(fn ($items) => [
                'type'  => $items->first()->discount_type,
                'value' => $perUnit($items->first()),
                'items' => $items->values()->all(),
            ])
            ->values()
            ->all();
    }

    public function discountTitle(Funnel $funnel, string $type, float $value): string
    {
        $template = $funnel->translations['discount_title'] ?? FunnelDefaults::translations()['discount_title'];
        $amount = $type === FunnelItem::PERCENTAGE
            ? rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . '%'
            : $this->money($value);

        return mb_substr(str_replace('{{DISCOUNT}}', $amount, $template), 0, 255);
    }

    // ------------------------------------------------------------------

    protected function createDiscounts(Funnel $funnel): void
    {
        if (config('bundles.discount_mode', 'function') === 'function') {
            $this->createFunctionDiscount($funnel);

            return;
        }

        $settings = FunnelDefaults::merge('settings', $funnel->settings);
        $ids = [];

        foreach ($this->discountGroups($funnel) as $group) {
            $minQuantity = array_sum(array_map(fn ($i) => (int) $i->quantity, $group['items']));
            $value = $group['type'] === FunnelItem::PERCENTAGE
                ? ['percentage' => round($group['value'] / 100, 4)]
                : ['discountAmount' => ['amount' => number_format($group['value'], 2, '.', ''), 'appliesOnEachItem' => true]];

            $input = [
                'title'       => $this->discountTitle($funnel, $group['type'], $group['value']),
                'startsAt'    => $settings['starts_at'] ?: now()->toIso8601String(),
                'endsAt'      => $settings['ends_at'] ?: null,
                'combinesWith' => [
                    'productDiscounts'  => true,
                    'orderDiscounts'    => (bool) $settings['combine_with_order_discounts'],
                    'shippingDiscounts' => (bool) $settings['combine_with_shipping_discounts'],
                ],
                'minimumRequirement' => ['quantity' => ['greaterThanOrEqualToQuantity' => (string) max(1, $minQuantity)]],
                'customerGets' => [
                    'value' => $value,
                    'items' => ['products' => ['productVariantsToAdd' => array_map(fn ($i) => $i->variant_id, $group['items'])]],
                ],
            ];

            $ids[] = $this->createDiscount($funnel, $input);
        }

        $funnel->forceFill(['discount_ids' => $ids])->saveQuietly();
        $this->step(count($ids) ? 'Created ' . count($ids) . ' automatic discount(s): ' . implode(', ', array_map(fn ($g) => $this->discountTitle($funnel, $g['type'], $g['value']), $this->discountGroups($funnel))) : 'No discount (no product has a discount)');
    }

    /**
     * One automatic app discount for the whole funnel, run by the bundle Discount Function.
     */
    protected function createFunctionDiscount(Funnel $funnel): void
    {
        $settings = FunnelDefaults::merge('settings', $funnel->settings);
        $items = $funnel->items->map(fn (FunnelItem $i) => [
            'variant_id' => $i->variant_id,
            'qty'        => (int) $i->quantity,
            'type'       => $i->discount_type,
            'value'      => (float) $i->discount_value,
            'message'    => $i->discount_type === FunnelItem::NONE ? null : $this->discountTitle($funnel, $i->discount_type, (float) $i->discount_value),
        ])->values()->all();

        $labels = array_values(array_unique(array_filter(array_column($items, 'message'))));
        if (! $labels) {
            $funnel->forceFill(['discount_ids' => []])->saveQuietly();
            $this->step('No discount (no product has a discount)');

            return;
        }

        $input = [
            'title'           => mb_substr('Scott bundle: ' . $funnel->name, 0, 255),
            'functionHandle'  => config('bundles.discount_function_handle', 'bundle-discount'),
            'discountClasses' => ['PRODUCT'],
            'startsAt'        => $settings['starts_at'] ?: now()->toIso8601String(),
            'endsAt'          => $settings['ends_at'] ?: null,
            'combinesWith'    => [
                'productDiscounts'  => true,
                'orderDiscounts'    => (bool) $settings['combine_with_order_discounts'],
                'shippingDiscounts' => (bool) $settings['combine_with_shipping_discounts'],
            ],
            'metafields' => [[
                'namespace' => self::CONFIG_NAMESPACE,
                'key'       => self::FUNCTION_CONFIG_KEY,
                'type'      => 'json',
                'value'     => json_encode([
                    'funnel_id' => $funnel->id,
                    'single'    => (bool) $settings['allow_single'],
                    'items'     => $items,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]],
        ];

        $data = $this->client->query(<<<'GQL'
            mutation($discount: DiscountAutomaticAppInput!) {
                discountAutomaticAppCreate(automaticAppDiscount: $discount) {
                    automaticAppDiscount { discountId }
                    userErrors { field message code }
                }
            }
        GQL, ['discount' => $input]);

        $errors = $data['discountAutomaticAppCreate']['userErrors'] ?? [];
        if ($errors) {
            $message = collect($errors)->pluck('message')->implode('; ');
            $lower = strtolower($message);
            if (str_contains($lower, 'function') || str_contains($lower, 'plus')) {
                $message .= ' The bundle discount runs as a Shopify Function: deploy the app (npm run deploy). On a live store, custom apps need Shopify Plus to run it; otherwise set BUNDLE_DISCOUNT_MODE=native.';
            }
            throw new ShopifyApiException('discountAutomaticAppCreate: ' . $message);
        }

        $id = $data['discountAutomaticAppCreate']['automaticAppDiscount']['discountId'];
        $funnel->forceFill(['discount_ids' => [$id]])->saveQuietly();
        $this->step('Created the bundle discount (applies only to the complete bundle): ' . implode(', ', $labels));
    }

    protected function createDiscount(Funnel $funnel, array $input): string
    {
        $mutation = <<<'GQL'
            mutation($discount: DiscountAutomaticBasicInput!) {
                discountAutomaticBasicCreate(automaticBasicDiscount: $discount) {
                    automaticDiscountNode { id }
                    userErrors { field message code }
                }
            }
        GQL;

        $data = $this->client->query($mutation, ['discount' => $input]);
        $errors = $data['discountAutomaticBasicCreate']['userErrors'] ?? [];

        // Titles are shared between funnels ("BUNDLE OFFER 10% OFF"); if Shopify wants it unique, add the funnel name.
        if ($errors && collect($errors)->contains(fn ($e) => str_contains(strtolower($e['message'] ?? ''), 'title'))) {
            $input['title'] = mb_substr($input['title'] . ' · ' . $funnel->name, 0, 255);
            $data = $this->client->query($mutation, ['discount' => $input]);
            $errors = $data['discountAutomaticBasicCreate']['userErrors'] ?? [];
        }

        if ($errors) {
            $message = collect($errors)->pluck('message')->implode('; ');
            if (str_contains(strtolower($message), 'limit') || str_contains(strtolower($message), 'maximum')) {
                $message .= ' Shopify allows about 25 active automatic discounts per store. Pause another funnel or merge discounts.';
            }
            throw new ShopifyApiException('discountAutomaticBasicCreate: ' . $message);
        }

        return $data['discountAutomaticBasicCreate']['automaticDiscountNode']['id'];
    }

    protected function deleteDiscounts(Funnel $funnel): void
    {
        $ids = $funnel->discount_ids ?? [];
        foreach ($ids as $id) {
            $data = $this->client->query(<<<'GQL'
                mutation($id: ID!) {
                    discountAutomaticDelete(id: $id) { deletedAutomaticDiscountId userErrors { field message } }
                }
            GQL, ['id' => $id]);

            $errors = $data['discountAutomaticDelete']['userErrors'] ?? [];
            // Already gone (deleted in the Shopify admin) is fine.
            if ($errors && ! collect($errors)->contains(fn ($e) => str_contains(strtolower($e['message'] ?? ''), 'not exist') || str_contains(strtolower($e['message'] ?? ''), 'not found'))) {
                throw new ShopifyApiException('discountAutomaticDelete: ' . collect($errors)->pluck('message')->implode('; '));
            }
        }

        if ($ids) {
            $funnel->forceFill(['discount_ids' => []])->saveQuietly();
            $this->step('Removed ' . count($ids) . ' previous discount(s)');
        }
    }

    /**
     * Write every active funnel to the app-owned metafield the storefront reads.
     */
    public function publishConfig(?int $excludeId = null): void
    {
        $funnels = Funnel::with('items')
            ->where('status', Funnel::ACTIVE)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->orderBy('id')
            ->get();

        $config = [
            'v'        => 1,
            'currency' => $this->currency(),
            'funnels'  => $funnels->map(fn ($f) => $this->storefrontFunnel($f))->values()->all(),
        ];

        $this->client->mutate('metafieldsSet', <<<'GQL'
            mutation($metafields: [MetafieldsSetInput!]!) {
                metafieldsSet(metafields: $metafields) { metafields { id } userErrors { field message } }
            }
        GQL, ['metafields' => [[
            'ownerId'   => $this->appInstallationId(),
            'namespace' => self::CONFIG_NAMESPACE,
            'key'       => self::CONFIG_KEY,
            'type'      => 'json',
            'value'     => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]]]);

        $this->step('Storefront config published (' . count($config['funnels']) . ' active funnel(s))');
    }

    public function storefrontFunnel(Funnel $f): array
    {
        $trigger = FunnelDefaults::merge('trigger', $f->trigger);
        $placements = FunnelDefaults::merge('placements', $f->placements);
        $settings = FunnelDefaults::merge('settings', $f->settings);

        return [
            'id'      => $f->id,
            'name'    => $f->name,
            'trigger' => [
                'all'         => (bool) $trigger['all'],
                'cart_min'    => ($trigger['cart_value']['enabled'] ?? false) ? (float) $trigger['cart_value']['min'] : null,
                'products'    => array_values(array_map(fn ($p) => (int) Gid::toId($p['id']), $trigger['products'])),
                'collections' => array_values(array_map(fn ($c) => (int) Gid::toId($c['id']), $trigger['collections'])),
                'tags'        => array_values(array_map('strval', $trigger['tags'])),
            ],
            'product_page' => ($placements['product_page']['enabled'] ?? false) ? ($placements['product_page']['style'] ?? 'inpage') : null,
            'cart_page'    => (bool) ($placements['cart_page']['enabled'] ?? false),
            'settings'     => [
                'hide_oos'           => (bool) $settings['hide_out_of_stock'],
                'show_compare'       => (bool) $settings['show_compare_price'],
                'hide_once_rejected' => (bool) $settings['hide_once_rejected'],
                'single'             => (bool) $settings['allow_single'],
                'after_accept'       => $settings['after_accept'],
                'reject'             => $settings['reject_behaviour'],
                'delay'              => (int) $settings['popup_delay'],
                'starts_at'          => $settings['starts_at'],
                'ends_at'            => $settings['ends_at'],
            ],
            'design' => FunnelDefaults::merge('design', $f->design),
            't'      => FunnelDefaults::merge('translations', $f->translations),
            'items'  => $f->items->map(fn ($i) => [
                'product_id' => (int) Gid::toId($i->product_id),
                'variant_id' => (int) Gid::toId($i->variant_id),
                'handle'     => $i->handle,
                'title'      => $i->product_title,
                'variant'    => $i->variant_title !== 'Default Title' ? $i->variant_title : null,
                'image'      => $i->image_url,
                'qty'        => $i->quantity,
                'max'        => $i->max_quantity,
                'type'       => $i->discount_type,
                'value'      => (float) $i->discount_value,
            ])->values()->all(),
        ];
    }

    protected function appInstallationId(): string
    {
        return Cache::remember('funnels.app_installation.' . md5($this->client->credentials()->shopDomain()), now()->addDay(), function () {
            $data = $this->client->query('{ currentAppInstallation { id } }');

            return $data['currentAppInstallation']['id'] ?? throw new ShopifyApiException('Could not read the app installation.');
        });
    }

    protected function currency(): string
    {
        return Cache::remember('funnels.currency.' . md5($this->client->credentials()->shopDomain()), now()->addDay(), function () {
            return $this->client->query('{ shop { currencyCode } }')['shop']['currencyCode'] ?? 'USD';
        });
    }

    protected function money(float $value): string
    {
        $currency = $this->currency();
        if (class_exists(\NumberFormatter::class)) {
            $formatted = (new \NumberFormatter('en', \NumberFormatter::CURRENCY))->formatCurrency($value, $currency);
            if ($formatted !== false) {
                return $formatted;
            }
        }

        // Without the intl extension: common symbols, otherwise the ISO code.
        $symbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€', 'INR' => '₹', 'AUD' => 'A$', 'CAD' => 'CA$', 'NZD' => 'NZ$', 'JPY' => '¥'];

        return isset($symbols[$currency])
            ? $symbols[$currency] . number_format($value, $currency === 'JPY' ? 0 : 2)
            : $currency . ' ' . number_format($value, 2);
    }

    // ------------------------------------------------------------------

    protected function run(Funnel $funnel, string $operation, callable $callback, bool $markSynced = true): void
    {
        $this->steps = [];
        $started = microtime(true);

        try {
            $callback();
            if ($markSynced) {
                $funnel->forceFill(['sync_status' => 'synced', 'last_synced_at' => now(), 'last_sync_error' => null])->saveQuietly();
            }
            $this->log($funnel, $operation, 'success', null, $started);
        } catch (Throwable $e) {
            $funnel->forceFill(['sync_status' => 'failed', 'last_sync_error' => $e->getMessage()])->saveQuietly();
            $this->log($funnel, $operation, 'failed', $e->getMessage(), $started);

            throw $e;
        }
    }

    protected function step(string $message): void
    {
        $this->steps[] = $message;
    }

    protected function log(Funnel $funnel, string $operation, string $status, ?string $error, float $started): void
    {
        BundleSyncLog::create([
            'funnel_id'   => $funnel->id,
            'operation'   => $operation,
            'status'      => $status,
            'details'     => ['steps' => $this->steps, 'funnel' => $funnel->name],
            'error'       => $error,
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            'user_id'     => auth()->id(),
        ]);
    }
}
