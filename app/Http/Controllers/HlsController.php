<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Audio;
use App\Models\CustomerEntitlement;
use App\Models\PlaySession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Str;

class HlsController extends Controller
{
    // Validate session token - tokenRaw is raw token given to client
    protected function validateSession(int $audioId, string $tokenRaw, Request $request): PlaySession
    {
        $hash = hash('sha256', $tokenRaw);
        $session = PlaySession::where('session_token', $hash)
            ->where('audio_id', $audioId)
            ->where('expires_at', '>', now())
            ->first();

        if (!$session) abort(403, 'Invalid or expired session');
        // Optional: check IP/user-agent
        // if ($session->ip && $session->ip !== $request->ip()) abort(403,'IP mismatch');

        // Auto-extend session TTL while user is active
        // Extend by 30 minutes on each validated request (segments/playlist/key)
        $session->expires_at = now()->addMinutes(30);
        $session->save();

        return $session;
    }

    public function playlist($audioId, $token, Request $request)
    {
        $session = $this->validateSession($audioId, $token, $request);

        $audio = Audio::findOrFail($audioId);
        if (!$audio->is_hls_ready || !$audio->hls_path) abort(404, 'HLS not ready');

        $playlistPath = $audio->hls_path . '/playlist.m3u8';
        if (!Storage::disk('private')->exists($playlistPath)) abort(404);

        $playlist = Storage::disk('private')->get($playlistPath);

        $keyUrl = route('hls.key', ['audio' => $audioId, 'token' => $token]);
        $playlist = str_replace('enc.keyuri', $keyUrl, $playlist);

        $playlist = preg_replace_callback('/(segment_[0-9]+\.ts)/', function ($m) use ($audioId, $token) {
            return route('hls.segment', ['audio' => $audioId, 'token' => $token, 'segment' => $m[1]]);
        }, $playlist);

        return response($playlist, 200)
            ->header('Content-Type', 'application/vnd.apple.mpegurl')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    public function segment($audioId, $token, $segment, Request $request)
    {
        $this->validateSession($audioId, $token, $request);

        $audio = Audio::findOrFail($audioId);
        $path = $audio->hls_path . '/' . $segment;
        if (!Storage::disk('private')->exists($path)) abort(404);

        $stream = Storage::disk('private')->readStream($path);
        return response()->stream(function () use ($stream) {
            fpassthru($stream);
        }, 200, [
            'Content-Type' => 'video/MP2T',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    public function key($audioId, $token, Request $request)
    {
        $this->validateSession($audioId, $token, $request);

        $audio = Audio::findOrFail($audioId);
        $keyPath = $audio->hls_path . '/enc.key';
        if (!Storage::disk('private')->exists($keyPath)) abort(404);

        $key = Storage::disk('private')->get($keyPath);

        // Optionally: mark the session used
        // $session->update(['used'=>true,'expires_at'=>now()]);

        return response($key, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => strlen($key),
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function download($audioId, $customerId, $signature)
    {
        try {
            $audio = Audio::findOrFail($audioId);

            // Validate signature
            $expected = hash_hmac('sha256', $audioId . '-' . $customerId, env('APP_KEY'));
            if (!hash_equals($expected, $signature)) {
                abort(403, 'Invalid download signature');
            }

            // Validate entitlement
            $ent = CustomerEntitlement::where('shopify_customer_id', $customerId)
                ->where('package_tag', $audio->package->shopify_tag ?? null)
                ->first();

            if (!$ent) abort(403);

            // Check if downloads allowed
            if (!$ent->is_download_allowed) {
                abort(403, 'Download not allowed for this customer.');
            }

            // Path to original MP3 (not HLS)
            $path = $audio->file_path;
            if (!Storage::disk('private')->exists($path)) {
                abort(404, 'File not found');
            }

            return Storage::disk('private')->download(
                $path,
                $audio->title . '.mp3',
                ['Content-Type' => 'audio/mpeg']
            );
        } catch (\Throwable $th) {
            abort(500);
        }
    }
}
