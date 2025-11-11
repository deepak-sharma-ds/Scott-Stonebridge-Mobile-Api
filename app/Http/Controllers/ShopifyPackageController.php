<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\PackageResource;
use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ShopifyPackageController extends Controller
{
    /**
     * GET /api/shopify/packages
     * Return all packages (with audios)
     */
    public function index(Request $request)
    {
        try {
            // Optionally filter or paginate
            $packages = Package::with('audios')
                ->where('status', 'active')
                ->latest()
                ->get();

            return response()->json([
                'status' => 200,
                'message' => 'Data found successfully',
                'packages' => PackageResource::collection($packages),
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => 'Something went wrong! Please try again later.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/shopify/packages/{id}
     * Return single package details
     */
    public function show($id)
    {
        try {
            $package = Package::with('audios')->findOrFail($id);

            // Convert the resource to an array
            $packageData = (new PackageResource($package))->toArray(request());

            // Append audio URLs dynamically
            $packageData['audios'] = $package->audios->map(function ($audio) {
                return [
                    'id' => $audio->id,
                    'title' => $audio->title,
                    'order_index' => $audio->order_index,
                    'duration_seconds' => $audio->duration_seconds,
                    'file_url' => $audio->file_path
                        ? route('audio.stream', $audio->id)
                        : null,
                ];
            });

            return response()->json([
                'status'  => 200,
                'message' => 'Data found successfully',
                'package' => $packageData,
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 500,
                'message' => 'Something went wrong! Please try again later.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }
}
