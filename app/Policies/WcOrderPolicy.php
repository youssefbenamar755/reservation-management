<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WcOrder;

class WcOrderPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // All authenticated users can view the orders list
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, WcOrder $order): bool
    {
        // User can view if they own the website or are an admin
        return $order->website->user_id === $user->id || $user->is_admin;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // Orders are created via webhooks, not by users
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, WcOrder $order): bool
    {
        // User can update if they own the website or are an admin
        return $order->website->user_id === $user->id || $user->is_admin;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, WcOrder $order): bool
    {
        // User can delete if they own the website or are an admin
        return $order->website->user_id === $user->id || $user->is_admin;
    }

    /**
     * Determine whether the user can generate Amadeus code for the order.
     */
    public function generateAmadeusCode(User $user, WcOrder $order): bool
    {
        // Same authorization as update
        return $this->update($user, $order);
    }
}
