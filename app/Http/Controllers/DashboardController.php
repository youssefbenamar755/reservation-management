<?php

namespace App\Http\Controllers;

use App\Services\DashboardOverview;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardOverview $dashboard)
    {
        $filters = $request->validate([
            'period' => ['sometimes', Rule::in(['today', '7d', '30d', 'month'])],
            'website_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $overview = $dashboard->build($request->user(), $filters);
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($overview)->header('Cache-Control', 'private, no-store');
        }

        return Inertia::render('Dashboard', $overview);
    }
}
