<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\PlaySession;
use App\Models\CustomerEntitlement;
use App\Models\Package;
use App\Models\Audio;
use App\Models\PlaybackSession;

class PlaySessionController extends Controller
{
    public function store(Request $request)
    {
        $request->validate(['audio_id' => 'required|integer', 'shopify_customer_id' => 'required']);

        $audioId = $request->audio_id;
        $shopifyCustomerId = $request->shopify_customer_id;

        $audio = Audio::with('package')->findOrFail($audioId);
        $pkg = $audio->package;
        if (!$pkg || !$pkg->shopify_tag) {
            return response()->json(['error' => 'Package tag not configured'], 403);
        }

        $entitled = CustomerEntitlement::where('shopify_customer_id', $shopifyCustomerId)
            ->where('package_tag', $pkg->shopify_tag)
            ->exists();

        if (!$entitled) return response()->json(['error' => 'Not entitled'], 403);

        $rawToken = Str::random(64);
        $hashed = hash('sha256', $rawToken);

        $session = PlaySession::create([
            'audio_id' => $audioId,
            'session_token' => $hashed,
            'user_id' => null,
            'ip' => $request->ip(),
            'user_agent' => substr($request->userAgent() ?? '', 0, 1024),
            'expires_at' => now()->addHours(3),
        ]);

        return response()->json([
            'status' => 'ok',
            'token' => $rawToken,
            'playlist_url' => route('hls.playlist', ['audio' => $audioId, 'token' => $rawToken])
        ]);
    }

    public function save(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|integer',
            'audio_id' => 'required|integer',
            'package_tag' => 'required|string',
            'position' => 'required|numeric|min:0',
        ]);

        PlaybackSession::updateOrCreate(
            [
                'customer_id' => $request->customer_id,
                'audio_id' => $request->audio_id,
                'package_tag' => $request->package_tag,
            ],
            [
                'last_position_seconds' => (float)$request->position,
            ]
        );

        return response()->json(['status' => 200, 'message' => 'Progress saved']);
    }
}
