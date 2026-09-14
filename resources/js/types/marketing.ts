export interface MarketingWebsite {
    id: number;
    name: string;
    base_url: string;
}
export interface MarketingCommon {
    websites: MarketingWebsite[];
    connected: boolean;
    senders: { email: string; name: string }[];
    testEmail: string | null;
}
export interface MarketingPage<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
}
export interface MarketingContent {
    locale: string;
    sender_email: string;
    sender_name: string;
    reply_to: string;
    subject: string;
    preheader: string;
    headline: string;
    body: string;
    signature: string;
    postal_address: string;
    logo_url: string;
    accent_color: string;
    cta_text: string;
    cta_url: string;
}
export interface MarketingTemplate {
    id: number;
    name: string;
    website_id: number;
    content: MarketingContent;
}
export interface MarketingCampaign {
    id: number;
    name: string;
    website_id: number;
    status: string;
    content: MarketingContent;
    audience: { locale: string | null; segment: string };
    scheduled_at: string | null;
    submitted_at: string | null;
    test_sent_at: string | null;
    recipient_count: number;
    result_message: string | null;
    created_at: string;
    provider_campaign_id: number | null;
}
export interface MarketingContact {
    id: number;
    email: string;
    name: string | null;
    locale: string | null;
    status: string;
    consent_source: string | null;
    consented_at: string | null;
    orders_count: number;
    completed_count: number;
    last_order_at: string | null;
    suppression: string | null;
}
