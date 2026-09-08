<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class EmailOpenController extends Controller
{
    public function __invoke(Request $request, string $token): Response
    {
        if ($request->isMethod('GET') && preg_match('/\A[a-f0-9]{64}\z/D', $token)) {
            try {
                // One atomic write preserves the first detection across concurrent image loads.
                // It does not load/decrypt the message or infer successful delivery.
                DB::table('order_email_deliveries')
                    ->where('tracking_token_hash', hash('sha256', $token))
                    ->where('tracking_enabled', true)
                    ->whereIn('status', ['sending', 'sent', 'uncertain'])
                    ->whereNotNull('sending_at')
                    ->whereNull('first_open_detected_at')
                    ->update(['first_open_detected_at' => now()]);
            } catch (QueryException) {
                // Image responses must not reveal database or token validity details.
                // Tracking is best effort; no recipient request information is logged.
            }
        }

        return response(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
