<?php

namespace App\Http\Controllers;

use App\Http\Requests\UsefulAlertRequest;
use App\Services\UsefulAlerts;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UsefulAlertController extends Controller
{
    public function index(UsefulAlertRequest $request, UsefulAlerts $alerts)
    {
        $props = $alerts->snapshot($request->user(), $request->filters(), (int) ($request->validated('page') ?? 1));

        return $request->expectsJson() && ! $request->header('X-Inertia')
            ? response()->json($props)->header('Cache-Control', 'private, no-store')
            : Inertia::render('Alerts/Index', $props)->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function refresh(UsefulAlertRequest $request, UsefulAlerts $alerts)
    {
        // Validate selected website access before any scan or notification mutation.
        $alerts->assertWebsite($request->user(), $request->filters()['website_id']);
        $alerts->scan($request->user());

        return response()->json($alerts->snapshot($request->user(), $request->filters(), (int) ($request->validated('page') ?? 1)))
            ->header('Cache-Control', 'private, no-store');
    }

    public function snooze(Request $request, int $alert, UsefulAlerts $alerts)
    {
        $alerts->snooze($request->user(), $alert, true);

        return response()->json(['message' => 'Alert snoozed for 24 hours.'])->header('Cache-Control', 'private, no-store');
    }

    public function resume(Request $request, int $alert, UsefulAlerts $alerts)
    {
        $alerts->snooze($request->user(), $alert, false);

        return response()->json(['message' => 'Alert resumed.'])->header('Cache-Control', 'private, no-store');
    }
}
