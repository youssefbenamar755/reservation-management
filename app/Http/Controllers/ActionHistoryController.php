<?php

namespace App\Http\Controllers;

use App\Http\Requests\ActionHistoryRequest;
use App\Services\ActionHistory;
use Inertia\Inertia;

class ActionHistoryController extends Controller
{
    public function redirect(ActionHistoryRequest $request)
    {
        return redirect()->route('action-history.index', $request->validated())->header('Cache-Control', 'private, no-store');
    }

    public function index(ActionHistoryRequest $request, ActionHistory $history)
    {
        $props = $history->snapshot($request->user(), $request->filters(), (int) ($request->validated('page') ?? 1));

        return $request->expectsJson() && ! $request->header('X-Inertia')
            ? response()->json($props)->header('Cache-Control', 'private, no-store')
            : Inertia::render('ActionHistory/Index', $props)->toResponse($request)->header('Cache-Control', 'private, no-store');
    }
}
