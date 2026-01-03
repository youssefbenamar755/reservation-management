<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

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
        ]);
    }

    /**
     * Mark a notification as read and return the redirect URL.
     */
    public function markAsRead(Request $request, string $id)
    {
        $notification = $request->user()
            ->notifications()
            ->findOrFail($id);

        if (!$notification->read_at) {
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
        }

        return response()->json([
            'success' => true,
            'redirect_url' => $redirectUrl,
        ]);
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['success' => true]);
    }
}

