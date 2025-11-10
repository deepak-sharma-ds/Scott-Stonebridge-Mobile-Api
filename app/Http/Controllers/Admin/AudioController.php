<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
    public function store(Request $request)
    {
        $data = $request->validate([
            'package_id' => 'required|exists:packages,id',
            'title' => 'required|string|max:255',
            'file' => 'required|file|mimes:mp3,wav',
            'duration_seconds' => 'nullable|integer'
        ]);

        $data['file_path'] = $request->file('file')->store('audios', 'private'); // or 's3'
        $data['order_index'] = Audio::where('package_id', $data['package_id'])->max('order_index') + 1;

        Audio::create($data);

        return redirect()->route('audios.index')->with('success', 'Audio added successfully');
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
    public function update(Request $request, Audio $audio)
    {
        $data = $request->validate([
            'package_id' => 'required|exists:packages,id',
            'title' => 'required|string|max:255',
            'file' => 'nullable|file|mimes:mp3,wav',
            'duration_seconds' => 'nullable|integer'
        ]);

        if ($request->hasFile('file')) {
            $data['file_path'] = $request->file('file')->store('audios', 'private'); // or 's3'
        }

        $audio->update($data);
        return redirect()->route('audios.index')->with('success', 'Audio updated');
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
