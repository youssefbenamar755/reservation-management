<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class DocumentValidationIsolation
{
    public function handle(Request $request, Closure $next)
    {
        // Imported production webhooks must never enter the fixture application.
        if (config('document_delivery.validation.enabled') && $request->is('api/*')) {
            return response()->json(['message' => 'External callbacks are disabled in document validation mode.'], 503);
        }

        return $next($request);
    }
}
