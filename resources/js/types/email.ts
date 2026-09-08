export type EmailDeliveryStatus =
    | 'prepared'
    | 'sending'
    | 'sent'
    | 'failed'
    | 'uncertain'
    | 'expired';

export interface EmailSender {
    email: string;
    name: string;
}
export interface EmailAttachment {
    name: string;
    size: number;
}
export interface EmailOpenTracking {
    enabled: boolean;
    first_open_detected_at: string | null;
}
export interface EmailPreview {
    id: string;
    recipient: string;
    sender: EmailSender;
    subject: string;
    body: string;
    attachments: EmailAttachment[];
    expires_at: string;
    status: EmailDeliveryStatus;
    tracking: EmailOpenTracking;
}
export interface EmailDelivery {
    id: string;
    status: EmailDeliveryStatus;
    message: string;
    sent_at: string | null;
    tracking: EmailOpenTracking;
}
export interface EmailHistory extends EmailDelivery {
    recipient: string;
    sender: EmailSender;
    subject: string;
    attachments: EmailAttachment[];
    created_at: string;
    expires_at: string;
}
export interface OrderEmailContext {
    connection: { connected: boolean; email: string | null };
    settings_url: string;
    sender: EmailSender | null;
    recipient: string;
    subject: string;
    body: string;
    limits: {
        max_files: number;
        max_file_bytes: number;
        max_total_bytes: number;
    };
    history: EmailHistory[];
}
