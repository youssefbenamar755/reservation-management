<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UserManagementController extends Controller
{
    /**
     * Display a listing of users.
     */
    public function index(Request $request)
    {
        $users = User::query()
            ->when($request->input('search'), function ($query, $search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            })
            ->orderBy('created_at', 'desc')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('settings/UserManagement', [
            'users' => $users,
            'filters' => $request->only(['search']),
        ]);
    }

    /**
     * Store a newly created user.
     */
    public function store(StoreUserRequest $request)
    {
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
            'is_admin' => $request->boolean('is_admin', false),
        ]);

        return back()->with('success', 'User created successfully.');
    }

    /**
     * Update the specified user.
     */
    public function update(UpdateUserRequest $request, User $user)
    {
        // Prevent removing admin from the last admin user
        if ($user->is_admin && !$request->boolean('is_admin')) {
            $adminCount = User::where('is_admin', true)->count();
            if ($adminCount <= 1) {
                return back()->withErrors(['is_admin' => 'Cannot remove admin privileges from the last admin user.']);
            }
        }

        $user->update([
            'name' => $request->name,
            'email' => $request->email,
            'is_admin' => $request->boolean('is_admin', false),
        ]);

        // Only update password if provided
        if ($request->filled('password')) {
            $user->update(['password' => $request->password]);
        }

        return back()->with('success', 'User updated successfully.');
    }

    /**
     * Remove the specified user.
     */
    public function destroy(User $user)
    {
        // Prevent deleting yourself
        if ($user->id === auth()->id()) {
            return back()->withErrors(['user' => 'You cannot delete your own account.']);
        }

        // Prevent deleting the last admin
        if ($user->is_admin) {
            $adminCount = User::where('is_admin', true)->count();
            if ($adminCount <= 1) {
                return back()->withErrors(['user' => 'Cannot delete the last admin user.']);
            }
        }

        $user->delete();

        return back()->with('success', 'User deleted successfully.');
    }
}
