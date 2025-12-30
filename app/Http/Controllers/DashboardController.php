<?php

namespace App\Http\Controllers;

use App\Models\FfSubmission;
use App\Models\Website;
use App\Models\WcOrder;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        // Get start and end of current month
        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now()->endOfMonth();

        // Overall Statistics
        $stats = [
            'total_websites' => Website::count(),
            'active_websites' => Website::where('status', 'active')->count(),
            'total_orders' => WcOrder::whereBetween('created_at_wp', [$startOfMonth, $endOfMonth])->count(),
            'total_submissions' => FfSubmission::whereBetween('created_at_wp', [$startOfMonth, $endOfMonth])->count(),
            'total_revenue' => WcOrder::where('status', 'completed')
                ->whereBetween('created_at_wp', [$startOfMonth, $endOfMonth])
                ->sum('total'),
            'pending_webhooks' => WebhookEvent::where('status', 'queued')->count(),
            'failed_webhooks' => WebhookEvent::where('status', 'failed')->count(),
        ];

        // Orders by Status (This Month)
        $ordersByStatus = WcOrder::select('status', DB::raw('count(*) as count'))
            ->whereBetween('created_at_wp', [$startOfMonth, $endOfMonth])
            ->groupBy('status')
            ->get()
            ->pluck('count', 'status')
            ->toArray();

        // Orders by Website (This Month)
        $ordersByWebsite = WcOrder::select('websites.name', DB::raw('count(wc_orders.id) as count'))
            ->join('websites', 'wc_orders.website_id', '=', 'websites.id')
            ->whereBetween('wc_orders.created_at_wp', [$startOfMonth, $endOfMonth])
            ->groupBy('websites.id', 'websites.name')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn ($item) => [
                'name' => $item->name,
                'count' => $item->count,
            ]);

        // Orders Over Time (Last 30 days)
        $ordersOverTime = WcOrder::select(
                DB::raw('DATE(created_at_wp) as date'),
                DB::raw('count(*) as count')
            )
            ->where('created_at_wp', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($item) => [
                'date' => $item->date,
                'count' => $item->count,
            ]);

        // Revenue Over Time (Last 30 days)
        $revenueOverTime = WcOrder::select(
                DB::raw('DATE(created_at_wp) as date'),
                DB::raw('SUM(total) as revenue')
            )
            ->where('status', 'completed')
            ->where('created_at_wp', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($item) => [
                'date' => $item->date,
                'revenue' => (float) $item->revenue,
            ]);

        // Recent Orders
        $recentOrders = WcOrder::with('website')
            ->latest('created_at_wp')
            ->limit(10)
            ->get()
            ->map(fn ($order) => [
                'id' => $order->id,
                'wp_order_id' => $order->wp_order_id,
                'website_name' => $order->website->name ?? 'Unknown',
                'status' => $order->status,
                'total' => $order->total,
                'currency' => $order->currency,
                'customer_email' => $order->customer_email,
                'customer_name' => $order->customer_name,
                'created_at_wp' => $order->created_at_wp?->format('Y-m-d H:i:s'),
            ]);

        // Recent Form Submissions
        $recentSubmissions = FfSubmission::with('website')
            ->latest('created_at_wp')
            ->limit(10)
            ->get()
            ->map(fn ($submission) => [
                'id' => $submission->id,
                'entry_id' => $submission->entry_id,
                'form_id' => $submission->form_id,
                'website_name' => $submission->website->name ?? 'Unknown',
                'email' => $submission->email,
                'created_at_wp' => $submission->created_at_wp?->format('Y-m-d H:i:s'),
            ]);

        // Website Health Status
        $websiteHealth = Website::withCount([
                'wcOrders',
                'ffSubmissions',
                'webhookEvents' => fn ($query) => $query->where('status', 'failed'),
            ])
            ->get()
            ->map(fn ($website) => [
                'id' => $website->id,
                'name' => $website->name,
                'status' => $website->status,
                'base_url' => $website->base_url,
                'orders_count' => $website->wc_orders_count,
                'submissions_count' => $website->ff_submissions_count,
                'failed_webhooks' => $website->webhook_events_count,
                'last_webhook_at' => $website->last_webhook_at?->format('Y-m-d H:i:s'),
                'last_sync_at' => $website->last_sync_at?->format('Y-m-d H:i:s'),
            ]);

        // Webhook Events Status
        $webhookStatus = [
            'queued' => WebhookEvent::where('status', 'queued')->count(),
            'processed' => WebhookEvent::where('status', 'processed')->count(),
            'failed' => WebhookEvent::where('status', 'failed')->count(),
        ];

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'ordersByStatus' => $ordersByStatus,
            'ordersByWebsite' => $ordersByWebsite,
            'ordersOverTime' => $ordersOverTime,
            'revenueOverTime' => $revenueOverTime,
            'recentOrders' => $recentOrders,
            'recentSubmissions' => $recentSubmissions,
            'websiteHealth' => $websiteHealth,
            'webhookStatus' => $webhookStatus,
        ]);
    }
}

