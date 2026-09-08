export interface DashboardComparison {
    current: number;
    previous: number;
    change: number;
    change_percent: number | null;
}

export interface DashboardRevenue extends DashboardComparison {
    currency: string;
    completed_orders: number;
    previous_completed_orders: number;
    average_order_value: number;
    previous_average_order_value: number;
}

export interface DashboardDay {
    date: string;
    orders: number;
    submissions: number;
    revenue: Record<string, number>;
}

export interface DashboardProps {
    filters: {
        period: 'today' | '7d' | '30d' | 'month';
        website_id: number | null;
    };
    period: {
        label: string;
        start_date: string;
        end_date: string;
        previous_start_date: string;
        previous_end_date: string;
        timezone: string;
    };
    websites: Array<{ id: number; name: string; status: string }>;
    summary: {
        orders: DashboardComparison;
        submissions: DashboardComparison;
        customers: DashboardComparison;
    };
    revenue: DashboardRevenue[];
    trend: DashboardDay[];
    statusBreakdown: Array<{ status: string; count: number }>;
    websitePerformance: Array<{
        id: number;
        name: string;
        status: string;
        orders: number;
        submissions: number;
        revenue: Array<{
            currency: string;
            total: number;
            completed_orders: number;
        }>;
    }>;
    recentOrders: Array<{
        id: number;
        wp_order_id: number;
        website_id: number;
        website_name: string;
        status: string;
        can_update_status: boolean;
        total: number | string;
        currency: string;
        customer_email: string | null;
        customer_name: string | null;
        created_at_wp: string | null;
    }>;
    recentSubmissions: Array<{
        id: number;
        entry_id: number;
        form_id: number;
        form_title: string | null;
        website_id: number;
        website_name: string;
        email: string | null;
        created_at_wp: string | null;
    }>;
    operations: {
        open_orders: number;
        pending: number;
        on_hold: number;
        processing: number;
        queued_webhooks: number;
        failed_webhooks: number;
        processed_webhooks: number;
        failed_webhooks_24h: number;
        oldest_queued_at: string | null;
        last_received_at: string | null;
        last_woo_received_at: string | null;
        last_fluent_received_at: string | null;
    };
    websiteHealth: Array<{
        id: number;
        name: string;
        status: string;
        base_url: string;
        orders_count: number;
        submissions_count: number;
        failed_webhooks: number;
        queued_webhooks: number;
        open_orders: number;
        last_webhook_at: string | null;
        last_sync_at: string | null;
        last_woo_received_at: string | null;
        last_fluent_received_at: string | null;
        wc_orders_synced_at: string | null;
    }>;
    generated_at: string;
}
