<?php

namespace App\Http\Controllers\Housekeeping;

use App\Http\Controllers\Controller;
use App\Http\Requests\Housekeeping\CategoryRequest;
use App\Models\Category;
use App\Support\CatalogMedia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

class CategoryController extends Controller
{
    public function index(): View
    {
        return view('housekeeping.categories.index', ['categories' => Category::withCount('products')->orderBy('sort_order')->orderBy('name')->paginate(15)]);
    }

    public function create(): View
    {
        return view('housekeeping.categories.form', ['category' => new Category(['is_active' => true, 'sort_order' => 0])]);
    }

    public function store(CategoryRequest $request, CatalogMedia $media): RedirectResponse
    {
        return $this->save($request, new Category, $media);
    }

    public function edit(Category $category): View
    {
        return view('housekeeping.categories.form', compact('category'));
    }

    public function update(CategoryRequest $request, Category $category, CatalogMedia $media): RedirectResponse
    {
        return $this->save($request, $category, $media);
    }

    public function status(Request $request, Category $category): RedirectResponse
    {
        $category->update($request->validate(['is_active' => ['required', 'boolean']]));

        return back()->with('status', 'Estado de la categoría actualizado.');
    }

    private function save(CategoryRequest $request, Category $category, CatalogMedia $media): RedirectResponse
    {
        $path = null;
        $oldPath = $category->image;
        try {
            if ($request->hasFile('image')) {
                $path = $media->store($request->file('image'), 'categories');
            }
            DB::transaction(function () use ($request, $category, $path) {
                $category->fill(Arr::except($request->validated(), ['image', 'remove_image']));
                if ($path) {
                    $category->image = $path;
                } elseif ($request->boolean('remove_image')) {
                    $category->image = null;
                }
                $category->save();
            });
        } catch (Throwable $error) {
            $media->discard($path);
            throw $error;
        }
        if ($oldPath !== $category->image) {
            $media->discard($oldPath);
        }

        return to_route('housekeeping.categories.edit', $category)->with('status', 'Categoría guardada correctamente.');
    }
}
