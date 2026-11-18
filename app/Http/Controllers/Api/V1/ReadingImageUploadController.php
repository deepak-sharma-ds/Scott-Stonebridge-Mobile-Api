<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReadingImageUploadRequest;
use App\Services\EmailReading\ReadingImageUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ReadingImageUploadController extends Controller
{
    /**
     * Public, unauthenticated and throttled (same constraint as ADR 0019: a
     * checkout extension cannot hold a secret). Accepts one image for an
     * "image" question slot and returns the `reference` the extension saves as
     * the answer on the cart line.
     */
    public function store(ReadingImageUploadRequest $request, ReadingImageUploadService $uploads): JsonResponse
    {
        try {
            $upload = $uploads->store($request->file("image"), $request->integer("product_id") ?: null);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'reference' => $upload->reference(),
        ], 201);
    }

    /**
     * Deletes the image the customer just replaced. Only an unclaimed upload
     * (not yet used for an order) can be removed, and only by its token link.
     */
    public function discard(Request $request, ReadingImageUploadService $uploads): JsonResponse
    {
        $uploads->discard((string) $request->input('reference'));

        return response()->json(['success' => true]);
    }
}
