<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class HousekeepingController extends Controller
{
    public const MODULES = [
        'productos' => 'Productos', 'categorias' => 'Categorías', 'pedidos' => 'Pedidos',
        'clientes' => 'Clientes', 'stock' => 'Stock', 'ofertas' => 'Ofertas',
        'configuracion' => 'Configuración',
    ];

    public function index(): View
    {
        return view('housekeeping.dashboard', [
            'modules' => self::MODULES,
            'stats' => [
                'Productos' => Product::count(), 'Categorías' => Category::count(),
                'Usuarios registrados' => User::count(),
                'Productos con stock bajo' => Product::where('is_active', true)->whereBetween('stock', [1, 5])->count(),
            ],
        ]);
    }

    public static function moduleUrl(string $module): string
    {
        return match ($module) {
            'productos' => route('housekeeping.products.index'),
            'categorias' => route('housekeeping.categories.index'),
            'pedidos' => route('housekeeping.orders.index'),
            'clientes' => route('housekeeping.customers.index'),
            default => route('housekeeping.module', $module),
        };
    }

    public function module(string $module): View|RedirectResponse
    {
        abort_unless(isset(self::MODULES[$module]), 404);

        if (in_array($module, ['productos', 'categorias', 'pedidos', 'clientes'])) {
            return redirect(self::moduleUrl($module));
        }

        return view('housekeeping.module', ['modules' => self::MODULES, 'module' => $module, 'title' => self::MODULES[$module]]);
    }
}
