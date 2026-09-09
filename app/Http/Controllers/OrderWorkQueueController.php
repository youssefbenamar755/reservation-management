<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrderWorkQueueRequest;
use App\Services\OrderWorkQueue;
use Inertia\Inertia;

class OrderWorkQueueController extends Controller
{
    public function index(OrderWorkQueueRequest $request, OrderWorkQueue $service)
    {
        $props = $service->snapshot($request->user(), $request->filters(), (int) ($request->validated('page') ?? 1));
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['queue' => $props['queue']])->header('Cache-Control', 'private, no-store');
        }

        return Inertia::render('Orders/WorkQueue', $props)->toResponse($request)->header('Cache-Control', 'private, no-store');
    }
}
