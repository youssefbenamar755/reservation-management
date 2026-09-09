<?php

namespace App\Http\Controllers;

use App\Models\UsefulAlert;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Get notifications for the authenticated user.
     */
    public function index(Request $request)
    {
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->take(50)
            ->get()
            ->map(function ($notification) {
                $data = $notification->data;

                return [
                    'id' => $notification->id,
                    'type' => $data['type'] ?? 'unknown',
                    'message' => $data['message'] ?? '',
                    'read_at' => $notification->read_at?->toISOString(),
                    'created_at' => $notification->created_at->toISOString(),
                    'data' => $data,
                ];
            });

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ])->header('Cache-Control', 'private, no-store');
    }

    /**
     * Mark a notification as read and return the redirect URL.
     */
    public function markAsRead(Request $request, string $id)
    {
        $notification = $request->user()
            ->notifications()
            ->findOrFail($id);

        if (! $notification->read_at) {
            $notification->markAsRead();
        }

        $data = $notification->data;
        $type = $data['type'] ?? null;
        $redirectUrl = null;

        // Determine redirect URL based on notification type
        if ($type === 'order') {
            $orderId = $data['order_id'] ?? null;
            if ($orderId) {
                $redirectUrl = route('orders.show', $orderId);
            }
        } elseif ($type === 'form_submission') {
            $submissionId = $data['submission_id'] ?? null;
            if ($submissionId) {
                $redirectUrl = route('submissions.entry-details', $submissionId);
            }
        } elseif ($type === 'useful_alert') {
            // Old inbox items must not retain access after website ownership changes.
            $alertId = $data['alert_id'] ?? null;
            $alert = is_int($alertId) && $alertId > 0
                ? UsefulAlert::where('user_id', $request->user()->id)
                    ->whereHas('website', fn ($query) => $query->when(! $request->user()->is_admin,
                        fn ($owned) => $owned->where('user_id', $request->user()->id)))
                    ->find($alertId)
                : null;
            $redirectUrl = route('alerts.index', $alert ? [
                'website_id' => $alert->website_id, 'kind' => $alert->kind, 'status' => 'all',
            ] : []);
        }

        return response()->json([
            'success' => true,
            'redirect_url' => $redirectUrl,
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead(Request $request)
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json([
            'success' => true,
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }
}
