<?php

namespace App\Http\Controllers\ShopifyApp;

use App\Http\Controllers\Controller;
use App\Models\Bundles\BundleSyncLog;
use App\Models\Funnels\FunnelOrder;
use App\Services\Bundles\BundleSetupService;
use App\Services\Bundles\ShopifyAdminClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class AppApiController extends Controller
{
    /**
     * Shop name + currency for formatting prices in the app.
     */
    public function bootstrap(BundleSetupService $setup): JsonResponse
    {
        $shop = Cache::remember('bundles.app_bootstrap', now()->addHour(), function () use ($setup) {
            $d = $setup->diagnostics();

            return [
                'name'     => $d['shop']['name'] ?? $d['shop_domain'],
                'domain'   => $d['shop_domain'],
                'currency' => $d['shop']['currencyCode'] ?? 'USD',
            ];
        });

        return response()->json(['shop' => $shop]);
    }

    public function setup(BundleSetupService $setup, ShopifyAdminClient $client): JsonResponse
    {
        return response()->json([
            'discount'        => $this->discountStatus($client),
            'diagnostics'     => $setup->diagnostics(),
            'required_scopes' => BundleSetupService::REQUIRED_SCOPES,
            'webhook_url'     => route('bundles.webhooks'),
            // Webhooks are registered by `shopify app deploy` from shopify.app.toml; these show they arrive.
            'last_order_webhook_at'   => FunnelOrder::max('created_at'),
            'last_product_webhook_at' => BundleSyncLog::where('operation', 'webhook')->max('created_at'),
        ]);
    }

    public function registerCartTransform(Request $request, BundleSetupService $setup): JsonResponse
    {
        $data = $request->validate(['function_id' => ['required', 'string', 'max:255']]);

        try {
            $setup->registerCartTransform($data['function_id']);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Could not register the cart transform: ' . Str::squish($e->getMessage())], 502);
        }

        return response()->json(['message' => 'Cart transform registered on the store.']);
    }

    /**
     * Which discount method is used and, for the Discount Function, whether it is deployed on the store.
     */
    protected function discountStatus(ShopifyAdminClient $client): array
    {
        $mode = config('bundles.discount_mode', 'function');
        $status = ['mode' => $mode, 'function_deployed' => null];
        if ($mode !== 'function') {
            return $status;
        }

        try {
            $nodes = $client->query('{ shopifyFunctions(first: 50) { nodes { title apiType } } }')['shopifyFunctions']['nodes'] ?? [];
            $status['function_deployed'] = collect($nodes)->contains(fn ($f) => ($f['title'] ?? '') === 'Scott bundle discount');
        } catch (Throwable) {
            // Unknown: shown as "couldn't check"
        }

        return $status;
    }

    public function logs(Request $request): JsonResponse
    {
        $logs = BundleSyncLog::with(['bundle', 'funnel', 'user'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('operation'), fn ($q) => $q->where('operation', $request->operation))
            ->latest()
            ->paginate(50);

        return response()->json([
            'data' => $logs->getCollection()->map(fn ($l) => Presenter::log($l))->all(),
            'meta' => ['page' => $logs->currentPage(), 'last_page' => $logs->lastPage(), 'total' => $logs->total()],
        ]);
    }
}
