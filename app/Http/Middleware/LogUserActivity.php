<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\ApiLog;
use Illuminate\Support\Facades\Log;

class LogUserActivity
{
    public function handle($request, Closure $next)
    {
        $resp = $next($request);

        try {
            $action = $request->route()?->getName() ?? $request->path();
            $meta = $request->all();

            // only log relevant actions
            $actionsToLog = ['search', 'wishlist.add', 'product.view', 'login', 'audio.download'];
            foreach ($actionsToLog as $a) {
                if (str_contains($action, $a) || $request->input('action') === $a) {
                    ApiLog::create([
                        'actor_type' => $request->user()?->getMorphClass() ?? 'customer',
                        'actor_id' => $request->user()?->id ?? $request->input('customer_id'),
                        'action' => str_replace('.', '_', $a),
                        'meta' => $meta,
                        'ip' => $request->ip(),
                    ]);
                    break;
                }
            }
        } catch (\Throwable $e) {
            // swallow; logging should not break requests
            Log::warning('Failed to write ApiLog: ' . $e->getMessage());
        }

        return $resp;
    }
}
