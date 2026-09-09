export interface TrafficApp {
    configured: boolean;
    client_id: string | null;
    has_secret: boolean;
    redirect_uri: string;
    can_manage: boolean;
}
export interface TrafficConnection {
    connected: boolean;
    email: string | null;
    reconnect_required?: boolean;
}
export interface TrafficWebsite {
    id: number;
    name: string;
    base_url: string;
    ga4_property_id: string;
    gsc_site_url: string;
}
export interface TrafficSettings {
    app: TrafficApp;
    connection: TrafficConnection;
    websites: TrafficWebsite[];
    catalog: {
        ga4: Array<{ id: string; name: string }>;
        gsc: Array<{ url: string; permission: string }>;
        errors: string[];
        loaded_at: string | null;
    };
}
export interface Ga4Totals {
    users: number;
    sessions: number;
    views: number;
    engagement_rate: number;
}
export interface SearchTotals {
    clicks: number;
    impressions: number;
    ctr: number;
    position: number;
}
export interface SearchRow extends SearchTotals {
    name: string;
}
export interface Ga4Report {
    totals: Ga4Totals;
    previous: Ga4Totals;
    daily: Array<{
        date: string;
        users: number;
        sessions: number;
        views: number;
    }>;
    sources: Array<{ name: string; sessions: number }>;
    pages: Array<{ name: string; views: number }>;
    countries: Array<{ name: string; users: number }>;
    devices: Array<{ name: string; users: number }>;
    timezone: string | null;
    error?: string | null;
}
export interface GscReport {
    totals: SearchTotals;
    previous: SearchTotals;
    daily: Array<{ date: string; clicks: number; impressions: number }>;
    queries: SearchRow[];
    pages: SearchRow[];
    error?: string | null;
}
export type TrafficReportStatus =
    | 'unlinked'
    | 'missing'
    | 'queued'
    | 'running'
    | 'ready'
    | 'failed';
export interface TrafficReport {
    website_id: number;
    website_name: string;
    ga4_property_id: string | null;
    gsc_site_url: string | null;
    status: TrafficReportStatus;
    updated_at: string | null;
    error: string | null;
    ga4: Ga4Report | null;
    gsc: GscReport | null;
    notes?: string[];
}
export interface TrafficFilters {
    website_id: number | null;
    start_date: string;
    end_date: string;
}
export interface TrafficPage {
    websites: Array<{ id: number; name: string }>;
    filters: TrafficFilters;
    connection: TrafficConnection;
    reports: TrafficReport[];
    orders: {
        total: number;
        completed: number;
        revenue: Array<{ currency: string; total: number }>;
    };
}
