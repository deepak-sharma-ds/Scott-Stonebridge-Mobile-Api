<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReadingImageUploadRequest;
use App\Services\EmailReading\ReadingImageUploadService;
use Illuminate\Http\JsonResponse;
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
            $upload = $uploads->store($request->file('image'));
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'reference' => $upload->reference(),
        ], 201);
    }
}
