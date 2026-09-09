<?php

namespace App\Http\Controllers;

use App\Http\Requests\SeoFilterRequest;
use App\Services\SeoReporting;
use Inertia\Inertia;

class SeoController extends Controller
{
    public function index(SeoFilterRequest $request, SeoReporting $reporting)
    {
        return Inertia::render('Seo', ['seo' => $reporting->page($request->user(), $request->filters())])
            ->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function status(SeoFilterRequest $request, SeoReporting $reporting)
    {
        return response()->json(['reports' => $reporting->reports($request->user(), $request->filters())])
            ->header('Cache-Control', 'private, no-store');
    }

    public function refresh(SeoFilterRequest $request, SeoReporting $reporting)
    {
        return $this->queued($reporting->queue($request->user(), $request->filters()));
    }

    public function detail(SeoFilterRequest $request, SeoReporting $reporting)
    {
        return response()->json(['report' => $reporting->detail($request->user(), $request->filters(), $request->validated('page_url'))])
            ->header('Cache-Control', 'private, no-store');
    }

    public function refreshPage(SeoFilterRequest $request, SeoReporting $reporting)
    {
        return $this->queued($reporting->queue($request->user(), $request->filters(), $request->validated('page_url')));
    }

    private function queued(int $count)
    {
        return response()->json(['message' => $count > 0 ? 'SEO analysis queued. It will start shortly.' : 'Analysis is already current, queued, or waiting briefly before retry.'], 202)
            ->header('Cache-Control', 'private, no-store');
    }
}
