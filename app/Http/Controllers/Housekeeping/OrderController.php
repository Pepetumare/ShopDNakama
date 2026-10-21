<?php

namespace App\Http\Controllers\Housekeeping;

use App\Http\Controllers\Controller;
use App\Http\Requests\Housekeeping\OrderFilterRequest;
use App\Http\Requests\Housekeeping\UpdateOrderStatusRequest;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function index(OrderFilterRequest $request): View
    {
        $filters = $request->validated();
        $query = Order::with('user');
        if (filled($filters['q'] ?? null)) {
            $search = '%'.$filters['q'].'%';
            $query->where(fn ($query) => $query->where('number', 'like', $search)->orWhere('customer_name', 'like', $search)
                ->orWhere('customer_email', 'like', $search)->orWhereHas('user', fn ($user) => $user->where('name', 'like', $search)->orWhere('email', 'like', $search)));
        }
        foreach (['status', 'payment_status'] as $field) {
            if ($filters[$field] ?? null) {
                $query->where($field, $filters[$field]);
            }
        }
        if ($filters['customer_id'] ?? null) {
            $query->where('user_id', $filters['customer_id']);
        }
        if ($filters['date_from'] ?? null) {
            $query->where('created_at', '>=', $filters['date_from'].' 00:00:00');
        }
        if ($filters['date_to'] ?? null) {
            $query->where('created_at', '<', Carbon::parse($filters['date_to'])->addDay()->startOfDay());
        }

        return view('housekeeping.orders.index', [
            'orders' => $query->latest()->orderByDesc('id')->paginate(15)->withQueryString(),
            'customers' => User::whereHas('orders')->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    public function show(Order $order): View
    {
        Gate::authorize('manage', $order);

        return view('housekeeping.orders.show', ['order' => $order->load(['user', 'items'])]);
    }

    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): RedirectResponse
    {
        Gate::authorize('manage', $order);
        $order->update($request->safe()->only('status'));

        return to_route('housekeeping.orders.show', $order)->with('status', 'Estado del pedido actualizado correctamente.');
    }
}
