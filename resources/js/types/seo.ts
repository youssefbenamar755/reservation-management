import type {
    TrafficConnection,
    TrafficFilters,
    TrafficReportStatus,
} from '@/types/traffic';

export type SeoOpportunityType = 'near_page_one' | 'low_ctr' | 'declining';
export interface SeoMetrics {
    clicks: number;
    impressions: number;
    ctr: number;
    position: number;
}
export interface SeoQueryComparison {
    name: string;
    current: SeoMetrics;
    previous: SeoMetrics | null;
    click_change: number | null;
}
export interface SeoOpportunity extends SeoQueryComparison {
    id: string;
    type: SeoOpportunityType;
    dimension: 'query' | 'page';
    decline_percent: number | null;
    reason: string;
    action: string;
}
export interface SeoPayload {
    opportunities: SeoOpportunity[];
    opportunity_count: number;
    coverage: {
        queries: number;
        pages: number;
        previous_queries: number;
        previous_pages: number;
        row_limit: 1000;
    };
    previous_start_date: string;
    previous_end_date: string;
    notes: string[];
    queries: SeoQueryComparison[];
}
export interface SeoReport {
    website_id: number;
    website_name: string;
    gsc_site_url: string | null;
    status: TrafficReportStatus;
    updated_at: string | null;
    error: string | null;
    data: SeoPayload | null;
}
export interface SeoPage {
    websites: Array<{ id: number; name: string }>;
    filters: TrafficFilters;
    max_end_date: string;
    connection: TrafficConnection;
    reports: SeoReport[];
}
export interface SeoOpportunityRow extends SeoOpportunity {
    website_id: number;
    website_name: string;
    key: string;
}
