<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Audio;

class AudioStreamController extends Controller
{
    public function stream($audioId, $file = 'playlist.m3u8')
    {
        try {
            $audio = Audio::findOrFail($audioId);
            $basePath = storage_path("app/private/{$audio->hls_path}");

            $target = "{$basePath}/{$file}";
            if (!file_exists($target)) {
                abort(404, 'File not found');
            }

            $ext  = pathinfo($target, PATHINFO_EXTENSION);
            $size = filesize($target);

            $mime = match ($ext) {
                'm3u8'  => 'application/vnd.apple.mpegurl',
                'ts'    => 'video/mp2t',
                'key'   => 'application/octet-stream',
                default => 'application/octet-stream',
            };

            // AES key must never be cached — a stale/BOM-corrupted cached key causes
            // WebCrypto importKey() to fail (expects exactly 16 bytes for AES-128),
            // which produces fragParsingError in HLS.js.
            $cacheControl = ($ext === 'key')
                ? 'no-store, no-cache, private, must-revalidate'
                : 'no-cache, must-revalidate, public';

            // Use a streamed response to bypass PHP output buffering entirely.
            // response()->file() can pass through ob_start() handlers on some shared
            // hosts that prepend a UTF-8 BOM (EF BB BF) to binary content, corrupting
            // the 16-byte AES key to 19 bytes and breaking HLS.js decryption.
            $headers = [
                'Content-Type'                  => $mime,
                'Content-Length'                => $size,
                'Cache-Control'                 => $cacheControl,
                'Access-Control-Allow-Origin'   => '*',
                'Access-Control-Allow-Methods'  => 'GET, OPTIONS',
                'Access-Control-Allow-Headers'  => '*',
                'X-Content-Type-Options'        => 'nosniff',
            ];

            return response()->stream(function () use ($target) {
                // Flush any existing output buffer to prevent BOM injection
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                readfile($target);
            }, 200, $headers);

        } catch (\Throwable $th) {
            abort(500, 'Error streaming audio');
        }
    }
}
