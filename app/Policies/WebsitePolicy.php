<?php

namespace App\Policies;

use Illuminate\Auth\Access\Response;
use App\Models\Website;
use App\Models\User;

class WebsitePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        // All authenticated users can view the website list (filtered by ownership)
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Website $website): bool
    {
        // User can view if they own the website or are an admin
        return $website->user_id === $user->id || $user->is_admin;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        // All authenticated users can create websites
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Website $website): bool
    {
        // User can update if they own the website or are an admin
        return $website->user_id === $user->id || $user->is_admin;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Website $website): bool
    {
        // User can delete if they own the website or are an admin
        return $website->user_id === $user->id || $user->is_admin;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Website $website): bool
    {
        // User can restore if they own the website or are an admin
        return $website->user_id === $user->id || $user->is_admin;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Website $website): bool
    {
        // User can force delete if they own the website or are an admin
        return $website->user_id === $user->id || $user->is_admin;
    }
}
