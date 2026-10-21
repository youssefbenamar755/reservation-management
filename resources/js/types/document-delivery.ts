export type DocumentLanguage = 'fr' | 'en';
export type DocumentTemplate = 'acknowledgement' | 'delivery' | 'correction';
export interface DocumentTemplateText {
    subject: string;
    heading: string;
    body: string;
    preheader: string;
    optional_block: string;
}
export interface DocumentBrandProfile {
    id?: number | null;
    brand_name: string;
    logo_url: string;
    primary_color: string;
    accent_color: string;
    background_color: string;
    text_color: string;
    support_email: string;
    website_url: string;
    support_phone: string;
    footer: string;
    gmail_connection_id: number | null;
    sender_email: string;
    sender_name: string;
    reply_to: string;
    default_language: DocumentLanguage;
    workflow_enabled: boolean;
    config_version: number;
    platform: string;
    store_pairing_key?: string | null;
    service_label: string;
    required_services: string[];
    store_capability: Record<string, unknown> | null;
    last_test_at: string | null;
    last_test_result: string | Record<string, unknown> | null;
    templates: Record<
        DocumentTemplate,
        Record<DocumentLanguage, DocumentTemplateText>
    >;
}
export interface BrandReadiness {
    ready: boolean;
    missing: string[];
    gmail_connected: boolean;
    sender_verified: boolean;
    templates_ready: boolean;
    completion_supported: boolean;
    platform: string;
}
export interface BrandConnection {
    id: number;
    email: string;
    connected: boolean;
    aliases: { email: string; name: string }[];
}
export interface DocumentWebsite {
    id: number;
    name: string;
    url: string;
    profile: DocumentBrandProfile;
    readiness: BrandReadiness;
    connections: BrandConnection[];
}
export interface ApprovedOrderDocument {
    id: string;
    name: string;
    size: number;
    sha256: string;
    version: number;
    approved: boolean;
}
export interface DocumentDeliveryOutcome {
    id: string;
    captured?: boolean;
    status: string;
    sync_status: string | null;
    sync_error?: string | null;
    message: string;
    sent_at: string | null;
    gmail_url?: string | null;
    review?: {
        decision: string;
        reason: string;
        reviewed_at: string;
        reviewer_name: string;
    } | null;
}
export interface DocumentDeliveryPreview {
    brand_name?: string;
    id: string;
    status: string;
    subject: string;
    html: string;
    body: string;
    text?: string;
    recipient: string;
    sender: { email: string; name: string };
    reply_to: string;
    document_set_version: string | number;
    config_version: number;
    attachments: {
        id?: string;
        name: string;
        size: number;
        sha256?: string;
        version?: number;
    }[];
    mode: 'complete' | 'partial' | 'resend' | 'correction';
    will_complete: boolean;
    suppression: boolean | string;
    expires_at?: string;
}
export type DocumentDeliveryHistory = DocumentDeliveryPreview &
    DocumentDeliveryOutcome;
export interface DocumentWorkflowContext {
    enabled: boolean;
    language?: DocumentLanguage;
    readiness: string[];
    profile: Pick<
        DocumentBrandProfile,
        | 'brand_name'
        | 'sender_email'
        | 'sender_name'
        | 'reply_to'
        | 'default_language'
        | 'config_version'
    >;
    documents: ApprovedOrderDocument[];
    required_services: string[];
    store: { supported: boolean; verified: boolean; message: string };
    document_set_version: string | number;
    deliveries: DocumentDeliveryHistory[];
}
