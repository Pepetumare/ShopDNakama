<?php

namespace App\Http\Controllers\Housekeeping;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerController extends Controller
{
    private function customers(): Builder
    {
        return User::withCount('orders')->withSum(['orders as total_spent' => fn ($query) => $query->paid()], 'total')->with('latestOrder');
    }

    public function index(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:255']]);
        $query = $this->customers();
        if (filled($filters['q'] ?? null)) {
            $query->where(fn ($query) => $query->where('name', 'like', '%'.$filters['q'].'%')->orWhere('email', 'like', '%'.$filters['q'].'%'));
        }

        return view('housekeeping.customers.index', ['customers' => $query->latest()->orderByDesc('id')->paginate(15)->withQueryString()]);
    }

    public function show(User $user): View
    {
        $customer = $this->customers()->whereKey($user->id)->firstOrFail();
        $addressFields = ['shipping_address_line1', 'shipping_address_line2', 'shipping_city', 'shipping_region', 'shipping_postal_code', 'shipping_country'];

        return view('housekeeping.customers.show', [
            'customer' => $customer,
            'orders' => $customer->orders()->latest()->orderByDesc('id')->paginate(15)->withQueryString(),
            'addresses' => $customer->orders()->whereNotNull('shipping_address_line1')->where('shipping_address_line1', '!=', '')->select($addressFields)->distinct()->get(),
        ]);
    }
}
