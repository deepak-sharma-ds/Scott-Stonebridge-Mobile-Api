<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ReadingImageUpload;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class ReadingImageController extends Controller
{
    /**
     * Public image link (unguessable token), used as the answer URL in Shopify and the checkout.
     */
    public function publicShow(string $token): Response
    {
        return $this->image($token, 'public, max-age=86400, no-transform');
    }

    /**
     * Streams a customer-uploaded reading image to a signed-in admin.
     */
    public function show(string $token): Response
    {
        return $this->image($token, 'private, max-age=300, no-transform');
    }

    /**
     * Sends the file as one plain response with an exact Content-Length, after discarding any
     * stray buffered output, so nothing can be prepended to the image bytes.
     */
    private function image(string $token, string $cacheControl): Response
    {
        $upload = ReadingImageUpload::where('token', $token)->firstOrFail();
        $disk = Storage::disk($upload->disk);

        abort_unless($disk->exists($upload->path), 404);

        $contents = (string) $disk->get($upload->path);

        if (ob_get_level() > 0) {
            ob_clean();
        }

        return response($contents, 200, [
            'Content-Type' => $upload->mime,
            'Content-Length' => (string) strlen($contents),
            'Content-Disposition' => 'inline',
            // Already-compressed binary: tell nginx/CDN not to gzip/brotli it again (double encoding broke it).
            'Content-Encoding' => 'identity',
            'Cache-Control' => $cacheControl,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
