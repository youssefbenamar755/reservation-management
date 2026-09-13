export interface HistoryFilters {
    website_id: number | null;
    actor_id: number | null;
    order_id: number | null;
    search: string;
    category: string;
    outcome: string;
    start_date: string;
    end_date: string;
}

export interface HistoryEntry {
    id: string;
    kind: string;
    title: string;
    message: string;
    outcome: string;
    actor: { id: number | null; name: string };
    website: { id: number; name: string };
    reference: string | null;
    occurred_at: string | null;
    finished_at: string | null;
    changes: { from?: string; requested?: string; confirmed?: string };
    attempt: number | null;
    action_url: string | null;
    action_label: string;
}

export interface ActionHistoryProps {
    history: {
        data: HistoryEntry[];
        summary: {
            total: number;
            succeeded: number;
            attention: number;
            pending: number;
        };
        total: number;
        current_page: number;
        last_page: number;
        per_page: number;
        from: number | null;
        to: number | null;
        generated_at: string;
        timezone: string;
    };
    websites: { id: number; name: string }[];
    people: { id: number; name: string }[];
    orderContext: { id: number; number: number; website_id: number } | null;
    filters: HistoryFilters;
}
