<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ReadingImageUpload;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReadingImageController extends Controller
{
    /**
     * Streams a customer-uploaded reading image to a signed-in admin.
     */
    public function publicShow(string $token): StreamedResponse
    {
        $upload = ReadingImageUpload::where('token', $token)->firstOrFail();
        $disk = Storage::disk($upload->disk);

        abort_unless($disk->exists($upload->path), 404);

        return $disk->response($upload->path, null, [
            'Content-Type' => $upload->mime,
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function show(string $token): StreamedResponse
    {
        $upload = ReadingImageUpload::where('token', $token)->firstOrFail();
        $disk = Storage::disk($upload->disk);

        abort_unless($disk->exists($upload->path), 404);

        return $disk->response($upload->path, null, [
            'Content-Type' => $upload->mime,
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
