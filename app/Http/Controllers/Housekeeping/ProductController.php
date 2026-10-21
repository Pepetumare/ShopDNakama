<?php

namespace App\Http\Controllers\Housekeeping;

use App\Http\Controllers\Controller;
use App\Http\Requests\Housekeeping\ProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ProductEditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:255'], 'category_id' => ['nullable', 'integer', 'exists:categories,id'], 'state' => ['nullable', 'in:active,inactive']]);
        $query = Product::with(['category', 'images']);
        if (filled($filters['q'] ?? null)) {
            $query->where(fn ($query) => $query->where('name', 'like', '%'.$filters['q'].'%')->orWhere('sku', 'like', '%'.$filters['q'].'%'));
        }
        if ($filters['category_id'] ?? null) {
            $query->where('category_id', $filters['category_id']);
        }
        if ($filters['state'] ?? null) {
            $query->where('is_active', $filters['state'] === 'active');
        }

        return view('housekeeping.products.index', ['products' => $query->latest()->orderByDesc('id')->paginate(15)->withQueryString(), 'categories' => Category::orderBy('name')->get()]);
    }

    public function create(): View
    {
        return $this->form(new Product(['is_active' => true, 'is_featured' => false, 'is_new' => false, 'is_customizable' => false, 'stock' => 0]));
    }

    public function store(ProductRequest $request, ProductEditor $editor): RedirectResponse
    {
        $product = $editor->save(new Product, $request->validated(), $request->file('uploads', []));

        return to_route('housekeeping.products.edit', $product)->with('status', 'Producto creado correctamente.');
    }

    public function edit(Product $product): View
    {
        return $this->form($product->load('images'));
    }

    public function update(ProductRequest $request, Product $product, ProductEditor $editor): RedirectResponse
    {
        $product = $editor->save($product, $request->validated(), $request->file('uploads', []));

        return to_route('housekeeping.products.edit', $product)->with('status', 'Producto guardado correctamente.');
    }

    public function status(Request $request, Product $product): RedirectResponse
    {
        $product->update($request->validate(['is_active' => ['required', 'boolean']]));

        return back()->with('status', 'Estado del producto actualizado.');
    }

    public function destroyImage(Product $product, ProductImage $image, ProductEditor $editor): RedirectResponse
    {
        $editor->removeImage($product, $image);

        return to_route('housekeeping.products.edit', $product)->with('status', 'Imagen eliminada correctamente.');
    }

    private function form(Product $product): View
    {
        return view('housekeeping.products.form', ['product' => $product, 'categories' => Category::orderBy('name')->get()]);
    }
}
