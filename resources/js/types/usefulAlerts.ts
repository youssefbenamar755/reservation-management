export type UsefulAlertKind =
    | 'webhook_failed'
    | 'webhook_stalled'
    | 'email_attention'
    | 'processing_aged';
export type UsefulAlertStatus = 'active' | 'snoozed' | 'resolved';
export interface UsefulAlert {
    id: number;
    kind: UsefulAlertKind;
    severity: 'warning' | 'critical';
    title: string;
    message: string;
    count: number;
    website: { id: number; name: string };
    first_detected_at: string;
    last_detected_at: string;
    resolved_at: string | null;
    snoozed_until: string | null;
    action_url: string;
    action_label: string;
    status: UsefulAlertStatus;
    examples?: Array<{ id: number; label: string; url: string }>;
}
export interface UsefulAlertCenter {
    data: UsefulAlert[];
    summary: Record<UsefulAlertStatus, number>;
    checked_at: string | null;
    thresholds: { webhook_minutes: number; processing_hours: number };
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}
export interface UsefulAlertFilters {
    website_id: number | null;
    status: UsefulAlertStatus | 'all';
    kind: UsefulAlertKind | 'all';
}
export interface UsefulAlertProps {
    alertCenter: UsefulAlertCenter;
    websites: Array<{ id: number; name: string }>;
    filters: UsefulAlertFilters;
}
