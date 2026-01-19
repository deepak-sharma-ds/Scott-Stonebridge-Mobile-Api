<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AudioRequest;
use App\Jobs\ConvertAudioToHls;
use App\Models\Audio;
use App\Models\Package;
use App\Services\AudioService;
use Illuminate\Support\Facades\Storage;

class AudioController extends Controller
{
    public function __construct(
        private AudioService $audioService
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // Optimized: Eager loads package to prevent N+1 queries
        $audios = $this->audioService->getPaginatedAudios(10);
        return view('admin.audios.index', compact('audios'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        // Only load necessary fields for dropdown
        $packages = Package::select('id', 'title')->orderBy('title')->get()->pluck('title', 'id');
        return view('admin.audios.create', compact('packages'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(AudioRequest $request)
    {
        try {
            $data = $request->validated();

            // Store uploaded audio file in private disk
            $data['file_path'] = $request->file('file')->store('audios', 'private');

            // Set next order index (handled by service)
            $data['is_hls_ready'] = false;

            // Create audio via service
            $audio = $this->audioService->createAudio($data);

            // Dispatch background HLS conversion job
            ConvertAudioToHls::dispatch(
                $audio->id,
                'private',
                $data['file_path']
            );

            return redirect()
                ->route('audios.index')
                ->with('success', 'Audio added successfully. Conversion started in background.');
        } catch (\Throwable $e) {
            report($e);
            return back()
                ->withInput()
                ->with('error', 'Failed to create audio. Please try again.');
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Audio $audio)
    {
        $audio->load('package:id,title,shopify_tag');
        return view('admin.audios.show', compact('audio'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Audio $audio)
    {
        $packages = Package::select('id', 'title')->orderBy('title')->get()->pluck('title', 'id');
        return view('admin.audios.edit', compact('audio', 'packages'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(AudioRequest $request, Audio $audio)
    {
        try {
            $data = $request->validated();
            $fileUpdated = false;

            // Handle new file upload
            if ($request->hasFile('file')) {
                // Delete old file
                if ($audio->file_path && Storage::disk('private')->exists($audio->file_path)) {
                    Storage::disk('private')->delete($audio->file_path);
                }

                // Store new file
                $data['file_path'] = $request->file('file')->store('audios', 'private');
                $data['is_hls_ready'] = false;
                $data['hls_path'] = null;
                $fileUpdated = true;

                // Restart conversion
                ConvertAudioToHls::dispatch(
                    $audio->id,
                    'private',
                    $data['file_path']
                );
            }

            // Update via service
            $this->audioService->updateAudio($audio, $data);

            $message = $fileUpdated 
                ? 'Audio updated successfully and conversion restarted.'
                : 'Audio updated successfully.';

            return redirect()
                ->route('audios.index')
                ->with('success', $message);
        } catch (\Throwable $e) {
            report($e);
            return back()
                ->withInput()
                ->with('error', 'Failed to update audio. Please try again.');
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Audio $audio)
    {
        try {
            $this->audioService->deleteAudio($audio);

            return redirect()
                ->route('audios.index')
                ->with('success', 'Audio deleted successfully');
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'Failed to delete audio.');
        }
    }
}
