<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CustomerEntitlement;
use App\Models\Package;
use App\Models\PlaybackSession;
use App\Models\PlaySession;
use Illuminate\Support\Str;

class AudioAccessController extends Controller
{
    public function show($shopifyCustomerId = 1, $packageTag = 'test-package-1', Request $request)
    {
        $customerId = $shopifyCustomerId;
        $ent = CustomerEntitlement::where('shopify_customer_id', $shopifyCustomerId)
            ->where('package_tag', $packageTag)
            ->first();

        if (!$ent) abort(403, 'Unauthorized');

        $canDownload = $ent->is_download_allowed;

        $package = Package::with('audios')->where('shopify_tag', $packageTag)->firstOrFail();

        $progressMap = PlaybackSession::where('customer_id', $shopifyCustomerId)
            ->where('package_tag', $packageTag)
            ->pluck('last_position_seconds', 'audio_id')
            ->toArray();

        $audios = $package->audios->map(function ($audio) use ($request, $shopifyCustomerId, $progressMap, $canDownload) {
            $raw = Str::random(64);
            $hashed = hash('sha256', $raw);
            PlaySession::where('audio_id', $audio->id)->where('user_id', $shopifyCustomerId)->forceDelete();
            PlaySession::create([
                'audio_id' => $audio->id,
                'session_token' => $hashed,
                'user_id' => $shopifyCustomerId,
                'ip' => $request->ip(),
                'user_agent' => substr($request->userAgent() ?? '', 0, 1024),
                'expires_at' => now()->addHours(3),
            ]);
            return [
                'id' => $audio->id,
                'title' => $audio->title,
                'playlist_url' => route('hls.playlist', ['audio' => $audio->id, 'token' => $raw]),
                'last_position_seconds' => (float)($progressMap[$audio->id] ?? 0),
                'download_url' => $canDownload
                    ? route('audio.download', [
                        'audioId' => $audio->id,
                        'customerId' => $shopifyCustomerId,
                        'signature' => hash_hmac('sha256', $audio->id . '-' . $shopifyCustomerId, env('APP_KEY'))
                    ])
                    : null,
            ];
        });

        // return response()->json([
        //     'status' => 200,
        //     'package' => [
        //         'id' => $package->id,
        //         'title' => $package->title,
        //         'tag' => $packageTag,
        //     ],
        //     'audios' => $audios,
        // ]);
        // dd($audios,$package);

        return view('audios.access', compact('package', 'audios', 'customerId'));
    }
}
