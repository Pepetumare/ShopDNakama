<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FavoriteController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $favorites = $request->user()->favorites()->with(['category', 'images'])
            ->withFavoriteState($request->user()->id)->orderByPivot('created_at', 'desc')
            ->orderByDesc('products.id')->paginate(12);
        if ($favorites->total() > 0 && $favorites->currentPage() > $favorites->lastPage()) {
            return to_route('account.favorites', ['page' => $favorites->lastPage()]);
        }

        return view('account.favorites', compact('favorites'));
    }

    public function store(Request $request, Product $product): RedirectResponse
    {
        abort_unless($product->is_active && $product->category->is_active, 404);
        $now = now();
        // The database unique key and insert-or-ignore also make concurrent adds idempotent.
        DB::table('favorites')->insertOrIgnore([
            'user_id' => $request->user()->id, 'product_id' => $product->id,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return back()->with('favorite-status', 'Producto guardado en tus favoritos.');
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        $request->user()->favorites()->detach($product->id);

        return back()->with('favorite-status', 'Producto quitado de tus favoritos.');
    }
}
