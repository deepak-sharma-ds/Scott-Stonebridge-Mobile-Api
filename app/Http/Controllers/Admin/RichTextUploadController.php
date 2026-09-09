<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class RichTextUploadController extends Controller
{
    /**
     * Stores an image dropped/pasted/uploaded into a rich-text editor
     * (email_content / email_footer) and returns its public URL in the
     * shape CKEditor's custom upload adapter expects.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'upload' => ['required', 'image', 'max:5120'],
        ]);

        $path = Storage::disk('public')->putFile('rich-text-uploads', $validated['upload']);

        return response()->json(['url' => asset('storage/'.$path)]);
    }
}
