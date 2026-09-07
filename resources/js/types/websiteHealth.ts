export interface HealthFilters {
    website_id: number | null;
    status: 'all' | 'queued' | 'processed' | 'failed';
    source: 'all' | 'woocommerce' | 'fluentforms';
    range: '24h' | '7d' | '30d' | 'all';
}

export interface DeliveryEvent {
    id: number;
    website_id: number;
    website: { id: number; name: string };
    source: string;
    topic: string;
    external_id: string | null;
    status: string;
    received_at: string | null;
    processed_at: string | null;
    failure_summary: string | null;
    attempts_count: number;
}

export interface RetryAttempt {
    id: number;
    status: string;
    requested_at: string;
    started_at: string | null;
    finished_at: string | null;
    result_message: string | null;
    requested_by: string | null;
}

export interface DeliveryDetail {
    event: DeliveryEvent & {
        signature_valid: boolean;
        can_retry: boolean;
        retry_reason: string | null;
    };
    attempts: RetryAttempt[];
}

export interface WebsiteHealth {
    summary: {
        failed_recent: number;
        failed_total: number;
        queued: number;
        processed_recent: number;
        oldest_queued_at: string | null;
    };
    sites: Array<{
        id: number;
        name: string;
        status: string;
        last_webhook_at: string | null;
        last_sync_at: string | null;
        wc_orders_synced_at: string | null;
        failed_recent: number;
        failed_total: number;
        queued: number;
        last_processed_at: string | null;
    }>;
    events: {
        data: DeliveryEvent[];
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    timezone: string;
    checked_at: string;
}
