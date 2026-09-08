<?php

namespace App\Http\Controllers\Admin\Concerns;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

trait StoresHeaderImages
{
    private function storeHeaderImage(UploadedFile $file, string $directory): string
    {
        $fileName = time().'-'.$file->hashName();

        Storage::disk('public')->putFileAs($directory, $file, $fileName);

        return $fileName;
    }
}
