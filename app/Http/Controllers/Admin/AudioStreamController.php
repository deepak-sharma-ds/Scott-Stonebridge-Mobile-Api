<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Audio;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AudioStreamController extends Controller
{
    public function stream($id)
    {
        $audio = Audio::findOrFail($id);

        // Check user authorization
        // e.g. verify Shopify tag, JWT, or Laravel auth
        // if (!auth()->check()) {
        //     abort(403, 'Unauthorized access');
        // }

        // Secure file path
        $path = 'audios/' . basename($audio->file_path);

        if (!Storage::disk('private')->exists($path)) {
            abort(404, 'Audio not found');
        }

        // Stream file without exposing location
        $stream = Storage::disk('private')->readStream($path);

        return new StreamedResponse(function () use ($stream) {
            fpassthru($stream);
        }, 200, [
            'Content-Type' => 'audio/mpeg',
            'Content-Length' => Storage::disk('private')->size($path),
            'Content-Disposition' => 'inline; filename="' . basename($path) . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }
}
