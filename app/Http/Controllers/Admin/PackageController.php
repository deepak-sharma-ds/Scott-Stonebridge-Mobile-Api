<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\PackageRequest;
use App\Models\Package;
use App\Services\PackageService;

class PackageController extends Controller
{
    public function __construct(
        private PackageService $packageService
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // Optimized: withCount prevents N+1, eager loads first 3 audios
        $packages = $this->packageService->getPaginatedPackages(10);
        return view('admin.packages.index', compact('packages'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('admin.packages.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(PackageRequest $request)
    {
        try {
            $this->packageService->createPackage(
                $request->validated(),
                $request->file('cover_image')
            );

            return redirect()
                ->route('packages.index')
                ->with('success', 'Package created successfully');
        } catch (\Throwable $e) {
            report($e); // Log to Laravel's error reporting
            return back()
                ->withInput()
                ->with('error', 'Failed to create package. Please try again.');
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Package $package)
    {
        // Load package with all audios for detailed view
        $package = $this->packageService->findPackageWithRelations($package->id);
        return view('admin.packages.show', compact('package'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Package $package)
    {
        return view('admin.packages.edit', compact('package'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(PackageRequest $request, Package $package)
    {
        try {
            $this->packageService->updatePackage(
                $package,
                $request->validated(),
                $request->file('cover_image')
            );

            return redirect()
                ->route('packages.index')
                ->with('success', 'Package updated successfully');
        } catch (\Throwable $e) {
            report($e);
            return back()
                ->withInput()
                ->with('error', 'Failed to update package. Please try again.');
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Package $package)
    {
        try {
            $this->packageService->deletePackage($package);
            
            return redirect()
                ->route('packages.index')
                ->with('success', 'Package deleted successfully');
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'Failed to delete package.');
        }
    }
}
