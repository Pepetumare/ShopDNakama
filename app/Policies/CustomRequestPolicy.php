<?php

namespace App\Policies;

use App\Models\CustomRequest;
use App\Models\User;

class CustomRequestPolicy
{
    public function view(User $user, CustomRequest $customRequest): bool
    {
        return $user->id === $customRequest->user_id;
    }

    public function viewFile(User $user, CustomRequest $customRequest): bool
    {
        return $user->isAdmin() || $this->view($user, $customRequest);
    }

    public function manage(User $user, CustomRequest $customRequest): bool
    {
        return $user->isAdmin();
    }

    public function accept(User $user, CustomRequest $customRequest): bool
    {
        // Administrative access does not grant consent on behalf of another customer.
        return $this->view($user, $customRequest);
    }

    public function decline(User $user, CustomRequest $customRequest): bool
    {
        return $this->view($user, $customRequest);
    }
}
