<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CustomerEntitlement;
use App\Models\Package;
use App\Models\PlaySession;
use Illuminate\Support\Str;

class AudioAccessController extends Controller
{
    public function show($shopifyCustomerId, $packageTag, Request $request)
    {
        $ent = CustomerEntitlement::where('shopify_customer_id', $shopifyCustomerId)
            ->where('package_tag', $packageTag)
            ->first();

        if (!$ent) abort(403, 'Unauthorized');

        $package = Package::with('audios')->where('shopify_tag', $packageTag)->firstOrFail();

        $audios = $package->audios->map(function ($audio) use ($request) {
            $raw = Str::random(64);
            $hashed = hash('sha256', $raw);
            PlaySession::create([
                'audio_id' => $audio->id,
                'session_token' => $hashed,
                'user_id' => null,
                'ip' => $request->ip(),
                'user_agent' => substr($request->userAgent() ?? '', 0, 1024),
                'expires_at' => now()->addHours(3),
            ]);
            return [
                'id' => $audio->id,
                'title' => $audio->title,
                'playlist_url' => route('hls.playlist', ['audio' => $audio->id, 'token' => $raw]),
            ];
        });

        return view('audios.access', compact('package', 'audios'));
    }
}
