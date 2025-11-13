<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AudioRequest;
use App\Jobs\ConvertAudioToHls;
use App\Models\Audio;
use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AudioController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $audios = Audio::with('package')->paginate(10);
        return view('admin.audios.index', compact('audios'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $packages = Package::pluck('title', 'id');
        return view('admin.audios.create', compact('packages'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(AudioRequest $request)
    {
        $data = $request->validated();

        // 1️⃣ Store uploaded audio file in the private disk (safe, not web-accessible)
        $data['file_path'] = $request->file('file')->store('audios', 'private');

        // 2️⃣ Determine next order index within the same package
        $data['order_index'] = Audio::where('package_id', $data['package_id'])->max('order_index') + 1;

        // 3️⃣ Initially mark as not converted yet
        $data['is_hls_ready'] = false;

        // 4️⃣ Create audio record
        $audio = Audio::create($data);

        // 5️⃣ Dispatch background HLS conversion job
        // This will generate HLS + AES-128 encrypted segments and playlist
        ConvertAudioToHls::dispatch(
            $audio->id,
            'private',                // source disk (matches store() above)
            $data['file_path']        // relative file path inside that disk
        );

        // 6️⃣ Return success response
        return redirect()
            ->route('audios.index')
            ->with('success', 'Audio added successfully. Conversion started in background.');
    }


    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Audio $audio)
    {
        $packages = Package::pluck('title', 'id');
        return view('admin.audios.edit', compact('audio', 'packages'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(AudioRequest $request, Audio $audio)
    {
        $data = $request->validated();

        // 1️⃣ If a new audio file is uploaded
        if ($request->hasFile('file')) {
            // Delete old private file if exists (prevent orphaned unprotected files)
            if ($audio->file_path && Storage::disk('private')->exists($audio->file_path)) {
                Storage::disk('private')->delete($audio->file_path);
            }

            // Store new uploaded file in private disk (never public)
            $data['file_path'] = $request->file('file')->store('audios', 'private');

            // Reset HLS-related fields to reprocess
            $data['is_hls_ready'] = false;
            $data['hls_path'] = null;

            // Dispatch conversion job (background HLS + AES encryption)
            ConvertAudioToHls::dispatch(
                $audio->id,
                'private',
                $data['file_path']
            );
        }

        // 2️⃣ Update record with new data
        $audio->update($data);

        // 3️⃣ Redirect back with success
        return redirect()
            ->route('audios.index')
            ->with('success', 'Audio updated successfully' . ($request->hasFile('file') ? ' and conversion restarted.' : '.'));
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Audio $audio)
    {
        $audio->delete();
        return back()->with('success', 'Audio deleted');
    }
}
