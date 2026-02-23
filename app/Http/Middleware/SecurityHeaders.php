<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Content Security Policy
        // Note: 'unsafe-inline' and 'unsafe-eval' needed for Vite in development
        // Consider tightening in production with nonces
        $csp = app()->environment('production')
            ? "default-src 'self'; " .
              "script-src 'self' 'unsafe-inline' 'unsafe-eval'; " .
              "style-src 'self' 'unsafe-inline'; " .
              "img-src 'self' data: https:; " .
              "font-src 'self' data:; " .
              "connect-src 'self'; " .
              "frame-ancestors 'none';"
            : "default-src 'self'; " .
              "script-src 'self' 'unsafe-inline' 'unsafe-eval'; " .
              "style-src 'self' 'unsafe-inline'; " .
              "img-src 'self' data: https:; " .
              "font-src 'self' data:; " .
              "connect-src 'self' ws: wss:; " . // Allow WebSocket for Vite HMR
              "frame-ancestors 'none';";

        $response->headers->set('Content-Security-Policy', $csp);

        // Prevent clickjacking
        $response->headers->set('X-Frame-Options', 'DENY');

        // Prevent MIME type sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Referrer Policy
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Permissions Policy (formerly Feature Policy)
        $response->headers->set('Permissions-Policy', 
            'geolocation=(), microphone=(), camera=(), payment=()'
        );

        // Strict-Transport-Security (HSTS) - only in production over HTTPS
        if (app()->environment('production') && $request->secure()) {
            $response->headers->set('Strict-Transport-Security', 
                'max-age=31536000; includeSubDomains; preload'
            );
        }

        return $response;
    }
}
