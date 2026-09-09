<?php

namespace App\Http\Controllers;

use App\Http\Requests\TrafficFilterRequest;
use App\Services\TrafficReporting;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TrafficController extends Controller
{
    public function index(TrafficFilterRequest $request, TrafficReporting $reporting)
    {
        return Inertia::render('Traffic', [
            'traffic' => $reporting->page($request->user(), $request->filters()),
        ])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function status(TrafficFilterRequest $request, TrafficReporting $reporting)
    {
        return response()->json(['reports' => $reporting->reports($request->user(), $request->filters())])
            ->header('Cache-Control', 'private, no-store');
    }

    public function refresh(TrafficFilterRequest $request, TrafficReporting $reporting)
    {
        $count = $reporting->queue($request->user(), $request->filters());

        return response()->json(['message' => $count > 0 ? 'Report refresh queued. It will start shortly.' : 'Reports are already current, queued, or waiting briefly before retry.'], 202)
            ->header('Cache-Control', 'private, no-store');
    }

    public function realtime(Request $request, TrafficReporting $reporting)
    {
        $values = $request->validate(['website_id' => ['required', 'integer', 'min:1']]);

        return response()->json($reporting->realtime($request->user(), (int) $values['website_id']))
            ->header('Cache-Control', 'private, no-store');
    }
}
