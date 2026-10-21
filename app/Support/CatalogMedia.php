<?php

namespace App\Support;

use App\Models\Category;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class CatalogMedia
{
    public function store(UploadedFile $file, string $directory): string
    {
        $path = $file->store($directory, 'public');
        if (! is_string($path)) {
            throw ValidationException::withMessages(['uploads' => 'No se pudo guardar la imagen. Inténtalo de nuevo.']);
        }

        return $path;
    }

    public function discard(?string $path): void
    {
        if ($path && ! str_contains($path, '..') && ! str_contains($path, '\\') && (str_starts_with($path, 'products/') || str_starts_with($path, 'categories/'))
            && ! ProductImage::where('image', $path)->exists() && ! Category::where('image', $path)->exists()) {
            Storage::disk('public')->delete($path);
        }
    }
}
