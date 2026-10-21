<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AccountOrderController extends Controller
{
    public function index(Request $request): View
    {
        return view('account.orders.index', [
            'orders' => $request->user()->orders()->latest()->orderByDesc('id')->paginate(15),
        ]);
    }

    public function show(Order $order): View
    {
        Gate::authorize('view', $order);

        return view('account.orders.show', ['order' => $order->load(['user', 'items'])]);
    }
}
