<?php

namespace App\Http\Controllers\Admin\Bundles;

use App\Http\Controllers\Controller;
use App\Models\Bundles\BundleSyncLog;
use App\Services\Bundles\BundleSetupService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class BundleSetupController extends Controller
{
    public function index(BundleSetupService $setup)
    {
        return view('admin.bundles.setup', [
            'diagnostics' => $setup->diagnostics(),
            'webhookUrl'  => route('bundles.webhooks'),
        ]);
    }

    public function registerCartTransform(Request $request, BundleSetupService $setup)
    {
        $data = $request->validate(['function_id' => ['required', 'string', 'max:255']]);

        try {
            $setup->registerCartTransform($data['function_id']);
        } catch (Throwable $e) {
            return back()->with('error', 'Could not register the cart transform: ' . Str::squish($e->getMessage()));
        }

        return back()->with('success', 'Cart transform registered on the store.');
    }

    public function logs(Request $request)
    {
        $logs = BundleSyncLog::with(['bundle', 'user'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('operation'), fn ($q) => $q->where('operation', $request->operation))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return view('admin.bundles.logs', compact('logs'));
    }
}
