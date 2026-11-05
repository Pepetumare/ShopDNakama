<?php

namespace App\Http\Controllers\Housekeeping;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\StockManager;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StockController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['q' => 'nullable|string|max:255', 'category_id' => 'nullable|integer|exists:categories,id', 'state' => 'nullable|in:available,low,empty', 'type' => ['nullable', Rule::in(array_keys(StockMovement::TYPES))], 'date_from' => 'nullable|date_format:Y-m-d', 'date_to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('date_from'), 'after_or_equal:date_from')]]);
        $products = Product::with(['category', 'images']);
        if (filled($filters['q'] ?? null)) {
            $products->where(fn ($q) => $q->where('name', 'like', '%'.$filters['q'].'%')->orWhere('sku', 'like', '%'.$filters['q'].'%'));
        }
        if ($filters['category_id'] ?? null) {
            $products->where('category_id', $filters['category_id']);
        }
        match ($filters['state'] ?? '') {
            'empty' => $products->where('stock', 0),
            'low' => $products->where('stock', '>', 0)->whereColumn('stock', '<=', 'minimum_stock'),
            'available' => $products->whereColumn('stock', '>', 'minimum_stock'),
            default => null,
        };
        $movements = StockMovement::with(['product', 'user', 'order'])->whereIn('product_id', (clone $products)->select('products.id'));
        if ($filters['type'] ?? null) {
            $movements->where('type', $filters['type']);
        }
        if ($filters['date_from'] ?? null) {
            $movements->where('created_at', '>=', $filters['date_from'].' 00:00:00');
        }
        if ($filters['date_to'] ?? null) {
            $movements->where('created_at', '<', Carbon::parse($filters['date_to'])->addDay());
        }

        return view('housekeeping.stock.index', [
            'products' => $products->orderBy('name')->orderBy('id')->paginate(15)->withQueryString(),
            'movements' => $movements->latest('id')->paginate(15, ['*'], 'movements_page')->withQueryString(),
            'categories' => Category::orderBy('name')->get(),
            'units' => Product::sum('stock'),
            'empty' => Product::where('stock', 0)->count(),
            'low' => Product::where('stock', '>', 0)->whereColumn('stock', '<=', 'minimum_stock')->count(),
        ]);
    }

    public function show(Product $product)
    {
        return view('housekeeping.stock.show', ['product' => $product->load(['category', 'images']), 'movements' => StockMovement::with(['user', 'order'])->where('product_id', $product->id)->latest('id')->paginate(20)]);
    }

    public function store(Request $request, Product $product, StockManager $stock)
    {
        $data = $request->validate(['type' => 'required|in:receipt,issue,count,damage,replacement', 'quantity' => 'required|integer|min:0|max:2147483647', 'reason' => 'required|string|min:5|max:1000', 'version' => 'required|integer|min:0', 'token' => 'required|uuid']);
        if ($data['type'] !== 'count' && (int) $data['quantity'] === 0) {
            throw ValidationException::withMessages(['quantity' => 'Ingresa una cantidad mayor a cero.']);
        }
        $stock->move($product, $data, $request->user()->id);

        return to_route('housekeeping.stock.show', $product)->with('status', 'Movimiento registrado correctamente.');
    }

    public function minimum(Request $request, Product $product)
    {
        $data = $request->validate(['minimum_stock' => 'required|integer|min:0|max:2147483647']);
        DB::transaction(function () use ($product, $data) {
            Product::whereKey($product->id)->lockForUpdate()->firstOrFail()->forceFill($data)->save();
        }, 3);

        return back()->with('status', 'Stock mínimo actualizado.');
    }

    public function restore(Request $request, StockManager $stock)
    {
        $data = $request->validate(['order_number' => 'required|string|max:255|exists:orders,number', 'reason' => 'required|string|min:5|max:1000', 'received' => 'required|accepted']);
        $stock->restore(Order::where('number', $data['order_number'])->firstOrFail(), $request->user()->id, $data['reason']);

        return back()->with('status', 'Devolución registrada. El pedido se repone una sola vez.');
    }
}
