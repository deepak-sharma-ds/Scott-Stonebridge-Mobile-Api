<?php

namespace App\Http\Controllers\ShopifyApp;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShopifyApp\FunnelRequest;
use App\Models\Funnels\Funnel;
use App\Models\Funnels\FunnelItem;
use App\Services\Funnels\FunnelAnalyticsService;
use App\Services\Funnels\FunnelDefaults;
use App\Services\Funnels\FunnelService;
use App\Services\Funnels\FunnelSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bundle funnels for the embedded app (UFE-style: trigger → offer → summary).
 */
class FunnelApiController extends Controller
{
    public function __construct(
        protected FunnelService $funnels,
        protected FunnelSyncService $sync,
        protected FunnelAnalyticsService $analytics,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = in_array((int) $request->per_page, [10, 25, 50], true) ? (int) $request->per_page : 10;
        $sort = in_array($request->sort, ['name', 'created_at', 'updated_at'], true) ? $request->sort : 'updated_at';

        $page = Funnel::with('items')
            ->when(in_array($request->status, [Funnel::ACTIVE, Funnel::INACTIVE], true), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%' . $request->q . '%'))
            ->orderBy($sort, $request->direction === 'asc' ? 'asc' : 'desc')
            ->paginate($perPage);

        $totals = $this->analytics->totalsByFunnel($page->getCollection()->pluck('id')->all());

        return response()->json([
            'data' => $page->getCollection()->map(fn ($f) => $this->row($f, $totals[$f->id] ?? ['conversions' => 0, 'revenue' => 0]))->all(),
            'meta' => [
                'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem(),
            ],
            'counts' => [
                'all'      => Funnel::count(),
                'active'   => Funnel::where('status', Funnel::ACTIVE)->count(),
                'inactive' => Funnel::where('status', Funnel::INACTIVE)->count(),
            ],
        ]);
    }

    public function defaults(): JsonResponse
    {
        return response()->json([
            'trigger'      => FunnelDefaults::trigger(),
            'placements'   => FunnelDefaults::placements(),
            'settings'     => FunnelDefaults::settings(),
            'design'       => FunnelDefaults::design(),
            'translations' => FunnelDefaults::translations(),
        ]);
    }

    public function show(Funnel $funnel): JsonResponse
    {
        return response()->json(['data' => $this->detail($funnel->load('items'))]);
    }

    public function store(FunnelRequest $request): JsonResponse
    {
        return $this->persist($request, null);
    }

    public function update(FunnelRequest $request, Funnel $funnel): JsonResponse
    {
        return $this->persist($request, $funnel);
    }

    /**
     * Validate only (live preview totals, MAP, SKUs).
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items'                  => ['required', 'array', 'min:1', 'max:20'],
            'items.*.variant_id'     => ['required', 'string'],
            'items.*.quantity'       => ['required', 'integer', 'min:1'],
            'items.*.discount_type'  => ['required', 'in:percentage,fixed,none'],
            'items.*.discount_value' => ['nullable', 'numeric', 'min:0'],
            'trigger'                => ['nullable', 'array'],
            'placements'             => ['nullable', 'array'],
            'settings'               => ['nullable', 'array'],
        ]);

        try {
            $prepared = $this->funnels->prepare($data);
        } catch (Throwable $e) {
            return $this->fail($this->message($e), 502);
        }

        return response()->json([
            'errors'   => $prepared['errors'],
            'warnings' => $prepared['warnings'],
            'totals'   => $prepared['totals'],
            'items'    => array_map(fn ($i) => ['variant_id' => $i['variant_id'], 'discounted' => $i['discounted'], 'price' => $i['price']], $prepared['items']),
        ]);
    }

    public function status(Request $request, Funnel $funnel): JsonResponse
    {
        $status = $request->validate(['status' => ['required', 'in:active,inactive']])['status'];

        if ($status === Funnel::ACTIVE) {
            $prepared = $this->funnels->prepare($this->inputFrom($funnel));
            if ($prepared['errors']) {
                return $this->fail('This funnel needs changes before it can be resumed.', 422, $prepared['errors']);
            }
        }

        $previous = $funnel->status;
        $funnel->update(['status' => $status]);

        try {
            $this->sync->sync($funnel->fresh('items'), $status === Funnel::ACTIVE ? 'activate' : 'deactivate');
        } catch (Throwable $e) {
            $funnel->update(['status' => $previous]);

            return $this->fail('Shopify sync failed: ' . $this->message($e), 502);
        }

        return $this->ok($funnel, $status === Funnel::ACTIVE
            ? 'Funnel resumed. The offer and its discount are live again.'
            : 'Funnel paused. The offer is hidden and its discount is off until you resume it.');
    }

    public function resync(Funnel $funnel): JsonResponse
    {
        try {
            $this->sync->sync($funnel->load('items'), 'resync');
        } catch (Throwable $e) {
            return $this->fail('Sync failed: ' . $this->message($e), 502);
        }

        $funnel->update(['needs_review' => false, 'review_reason' => null]);

        return $this->ok($funnel, 'Funnel synced to Shopify.');
    }

    public function rename(Request $request, Funnel $funnel): JsonResponse
    {
        $funnel->update(['name' => trim($request->validate(['name' => ['required', 'string', 'max:255']])['name'])]);

        return $this->ok($funnel, 'Funnel renamed.');
    }

    public function duplicate(Funnel $funnel): JsonResponse
    {
        $copy = $this->funnels->duplicate($funnel->load('items'));

        return $this->ok($copy, 'Funnel duplicated. The copy is inactive.');
    }

    public function destroy(Funnel $funnel): JsonResponse
    {
        try {
            $this->sync->remove($funnel);
        } catch (Throwable $e) {
            return $this->fail('Could not remove the funnel discount in Shopify: ' . $this->message($e), 502);
        }

        $funnel->delete();

        return response()->json(['message' => 'Funnel deleted.']);
    }

    // ------------------------------------------------------------------

    protected function persist(FunnelRequest $request, ?Funnel $funnel): JsonResponse
    {
        $data = $request->funnelData();

        try {
            $prepared = $this->funnels->prepare($data);
        } catch (Throwable $e) {
            return $this->fail('Could not load products from Shopify: ' . $this->message($e), 502);
        }

        // Saving as inactive is allowed with warnings; errors always block.
        if ($prepared['errors']) {
            return $this->fail('The funnel could not be saved.', 422, $prepared['errors'], $prepared['warnings']);
        }

        $funnel = $this->funnels->save($funnel, $data, $prepared);

        try {
            $this->sync->sync($funnel, $funnel->wasRecentlyCreated ? 'create' : 'update');
        } catch (Throwable $e) {
            return response()->json([
                'message'    => 'Funnel saved, but the Shopify sync failed: ' . $this->message($e) . ' Use "Sync to Shopify" to retry.',
                'sync_error' => true,
                'warnings'   => $prepared['warnings'],
                'data'       => $this->detail($funnel->fresh('items')),
            ]);
        }

        return $this->ok($funnel, $funnel->isActive() ? 'Funnel saved and live.' : 'Funnel saved.', $prepared['warnings']);
    }

    /**
     * Current funnel as validation input (used before activating).
     */
    protected function inputFrom(Funnel $funnel): array
    {
        return [
            'items' => $funnel->items->map(fn ($i) => [
                'variant_id' => $i->variant_id, 'quantity' => $i->quantity, 'max_quantity' => $i->max_quantity,
                'discount_type' => $i->discount_type, 'discount_value' => (float) $i->discount_value,
            ])->all(),
            'trigger'    => $funnel->trigger,
            'placements' => $funnel->placements,
            'settings'   => $funnel->settings,
        ];
    }

    protected function row(Funnel $f, array $totals): array
    {
        return [
            'id'           => $f->id,
            'name'         => $f->name,
            'status'       => $f->status,
            'published'    => $f->published_at !== null,
            'sync_status'  => $f->sync_status,
            'needs_review' => $f->needs_review,
            'pages'        => $f->pageLabels(),
            'triggers'     => $this->triggerSummary($f),
            'items_count'  => $f->items->sum('quantity'),
            'conversions'  => $totals['conversions'],
            'revenue'      => $totals['revenue'],
            'updated_at'   => $f->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Discount labels shoppers see at checkout, with the number of products each.
     * Function mode: one label per product, with the amount as entered (a fixed amount is per bundle line).
     */
    protected function checkoutLabels(Funnel $f): array
    {
        if (config('bundles.discount_mode', 'function') !== 'function') {
            return array_map(fn ($g) => [
                'title' => $this->sync->discountTitle($f, $g['type'], $g['value']),
                'items' => count($g['items']),
            ], $this->sync->discountGroups($f));
        }

        return $f->items
            ->reject(fn ($i) => $i->discount_type === FunnelItem::NONE || (float) $i->discount_value <= 0)
            ->countBy(fn ($i) => $this->sync->discountTitle($f, $i->discount_type, (float) $i->discount_value))
            ->map(fn ($count, $title) => ['title' => $title, 'items' => $count])
            ->values()
            ->all();
    }

    protected function detail(Funnel $f): array
    {
        $totals = $this->analytics->totalsByFunnel([$f->id])[$f->id] ?? ['conversions' => 0, 'revenue' => 0];

        return $this->row($f, $totals) + [
            'trigger'         => FunnelDefaults::merge('trigger', $f->trigger),
            'placements'      => FunnelDefaults::merge('placements', $f->placements),
            'settings'        => FunnelDefaults::merge('settings', $f->settings),
            'design'          => FunnelDefaults::merge('design', $f->design),
            'translations'    => FunnelDefaults::merge('translations', $f->translations),
            'discounts'       => $this->checkoutLabels($f),
            'last_synced_at'  => $f->last_synced_at?->toIso8601String(),
            'last_sync_error' => $f->last_sync_error,
            'review_reason'   => $f->review_reason,
            'created_at'      => $f->created_at?->toIso8601String(),
            'items'           => $f->items->map(fn (FunnelItem $i) => [
                'variant_id'     => $i->variant_id,
                'product_id'     => $i->product_id,
                'title'          => $i->title,
                'product_title'  => $i->product_title,
                'variant_title'  => $i->variant_title,
                'handle'         => $i->handle,
                'sku'            => $i->sku,
                'image'          => $i->image_url,
                'price'          => (float) $i->price,
                'quantity'       => $i->quantity,
                'max_quantity'   => $i->max_quantity,
                'discount_type'  => $i->discount_type,
                'discount_value' => (float) $i->discount_value,
                'discounted'     => $i->discounted_price,
            ])->values()->all(),
        ];
    }

    protected function triggerSummary(Funnel $f): array
    {
        $t = FunnelDefaults::merge('trigger', $f->trigger);

        return [
            'all'         => (bool) $t['all'],
            'cart_value'  => ($t['cart_value']['enabled'] ?? false) ? (float) $t['cart_value']['min'] : null,
            'products'    => array_slice(array_map(fn ($p) => ['title' => $p['title'] ?? '', 'image' => $p['image'] ?? null], $t['products']), 0, 3),
            'product_count'    => count($t['products']),
            'collection_count' => count($t['collections']),
            'tag_count'        => count($t['tags']),
        ];
    }

    protected function ok(Funnel $funnel, string $message, array $warnings = []): JsonResponse
    {
        return response()->json(['message' => $message, 'warnings' => $warnings, 'data' => $this->detail($funnel->fresh('items'))]);
    }

    protected function fail(string $message, int $status, array $errors = [], array $warnings = []): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => ['items' => $errors], 'warnings' => $warnings], $status);
    }

    protected function message(Throwable $e): string
    {
        return Str::limit(Str::squish($e->getMessage()), 600);
    }
}
