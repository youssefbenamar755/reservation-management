import type { EmailDeliveryStatus } from '@/types/email';

export type OrderQueueStage =
    | 'prepare'
    | 'ready'
    | 'sending'
    | 'sent'
    | 'attention'
    | 'waiting';
export interface OrderQueueFilters {
    website_id: number | string | null;
    search: string;
    stage: 'all' | OrderQueueStage;
    sort: 'oldest' | 'newest';
    per_page: number | string;
}
export interface OrderQueueRow {
    id: number;
    wp_order_id: number;
    website_id: number;
    website: { id: number; name: string };
    status: string;
    can_update_status: boolean;
    customer_name: string | null;
    customer_email: string | null;
    total: number | string;
    currency: string | null;
    created_at_wp: string | null;
    stage: OrderQueueStage;
    email: null | {
        status: EmailDeliveryStatus;
        created_at: string | null;
        sent_at: string | null;
        expires_at: string | null;
    };
    submission_id: number | null;
}
export interface OrderQueueSnapshot {
    data: OrderQueueRow[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    summary: Record<'all' | OrderQueueStage, number>;
    generated_at: string;
    timezone: string;
}
export interface OrderQueueProps {
    queue: OrderQueueSnapshot;
    websites: Array<{ id: number; name: string }>;
    filters: OrderQueueFilters;
}
