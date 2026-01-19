<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Audio;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AudioStreamController extends Controller
{
    public function stream($audioId, $file = 'playlist.m3u8')
    {
        $audio = Audio::findOrFail($audioId);
        $basePath = storage_path("app/private/{$audio->hls_path}");

        $target = "{$basePath}/{$file}";
        if (!file_exists($target)) {
            abort(404, 'File not found');
        }

        $ext = pathinfo($target, PATHINFO_EXTENSION);
        $mime = match ($ext) {
            'm3u8' => 'application/vnd.apple.mpegurl',
            'ts'   => 'video/mp2t',
            'key'  => 'application/octet-stream',
            default => 'application/octet-stream'
        };

        return response()->file($target, [
            'Content-Type' => $mime,
            'Cache-Control' => 'no-cache, must-revalidate',
            'Access-Control-Allow-Origin' => 'https://chapter-verse-ds.myshopify.com',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => '*',
        ]);
    }
}
