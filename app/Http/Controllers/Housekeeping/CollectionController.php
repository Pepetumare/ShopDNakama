<?php

namespace App\Http\Controllers\Housekeeping;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\ProductCollectionVolume;
use App\Support\CatalogMedia;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class CollectionController extends Controller
{
    public function index(): View
    {
        $collections = ProductCollection::with('category')->withCount('volumes')
            ->orderBy('sort_order')->orderBy('name')->orderBy('id')->paginate(25);

        return view('housekeeping.collections.index', compact('collections'));
    }

    public function create(): View
    {
        return $this->form(new ProductCollection(['sort_order' => 0]));
    }

    public function edit(ProductCollection $collection): View
    {
        return $this->form($collection);
    }

    private function form(ProductCollection $collection): View
    {
        $categories = Category::orderBy('name')->pluck('name', 'id');
        $volumes = $collection->exists
            ? $collection->volumes()->with('product.category')->orderBy('sort_order')->orderBy('volume_number')->get()
            : collect();
        $products = $collection->exists
            ? Product::whereDoesntHave('collectionVolume')->orderBy('name')->orderBy('id')->get(['id', 'name', 'sku'])
            : collect();

        return view('housekeeping.collections.form', compact('collection', 'categories', 'products', 'volumes'));
    }

    public function store(Request $request, CatalogMedia $media): RedirectResponse
    {
        return $this->save($request, new ProductCollection, $media);
    }

    public function update(Request $request, ProductCollection $collection, CatalogMedia $media): RedirectResponse
    {
        return $this->save($request, $collection, $media);
    }

    private function save(Request $request, ProductCollection $collection, CatalogMedia $media): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'description' => ['nullable', 'string', 'max:10000'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:5120'],
            'is_published' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'between:0,2147483647'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:255'],
        ]);

        unset($data['image']);
        $newImage = $request->hasFile('image') ? $media->store($request->file('image'), 'collections') : null;
        if ($newImage !== null) {
            $data['image'] = $newImage;
        }
        try {
            [$collection, $previousImage] = DB::transaction(function () use ($collection, $data) {
                if ($collection->exists) {
                    $collection = ProductCollection::whereKey($collection->id)->lockForUpdate()->firstOrFail();
                }
                $previousImage = $collection->image;
                $collection->fill($data)->save();

                return [$collection, $previousImage];
            });
        } catch (Throwable $error) {
            if ($newImage !== null) {
                Storage::disk('public')->delete($newImage);
            }
            if ($error instanceof UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['name' => 'Otra colección acaba de usar ese slug. Vuelve a guardar.']);
            }
            throw $error;
        }

        if ($newImage !== null && $previousImage && str_starts_with($previousImage, 'collections/')) {
            Storage::disk('public')->delete($previousImage);
        }

        return to_route('housekeeping.collections.edit', $collection)->with('status', 'Colección guardada.');
    }

    public function storeVolume(Request $request, ProductCollection $collection): RedirectResponse
    {
        return $this->saveVolume($request, $collection, new ProductCollectionVolume);
    }

    public function updateVolume(Request $request, ProductCollection $collection, ProductCollectionVolume $volume): RedirectResponse
    {
        return $this->saveVolume($request, $collection, $volume);
    }

    private function saveVolume(Request $request, ProductCollection $collection, ProductCollectionVolume $volume): RedirectResponse
    {
        $number = Rule::unique('product_collection_volumes', 'volume_number')->where('product_collection_id', $collection->id);
        if ($volume->exists) {
            $number->ignore($volume);
        }

        $rules = [
            'volume_number' => ['required', 'integer', 'between:1,2147483647', $number],
            'sort_order' => ['required', 'integer', 'between:0,2147483647'],
        ];
        if (! $volume->exists) {
            $rules['product_id'] = ['required', 'integer', 'exists:products,id', 'unique:product_collection_volumes,product_id'];
        }

        $volume->fill($request->validate($rules));
        try {
            $collection->volumes()->save($volume);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['volume_number' => 'El número de tomo o el producto ya está asociado.']);
        }

        return back()->with('status', 'Tomo guardado.');
    }

    public function destroyVolume(ProductCollection $collection, ProductCollectionVolume $volume): RedirectResponse
    {
        $volume->delete();

        return back()->with('status', 'Tomo retirado de la colección.');
    }
}
