<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    // Even admins see only their own orders in the customer account area.
    public function view(User $user, Order $order): bool
    {
        return $order->user_id === $user->id;
    }

    public function manage(User $user, Order $order): bool
    {
        return $user->isAdmin();
    }
}
