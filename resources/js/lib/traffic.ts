import type {
    Ga4Report,
    Ga4Totals,
    GscReport,
    SearchRow,
    SearchTotals,
    TrafficReport,
} from '@/types/traffic';

const finite = (value: unknown): value is number =>
    typeof value === 'number' && Number.isFinite(value) && value >= 0;
const record = (value: unknown): value is Record<string, unknown> =>
    !!value && typeof value === 'object' && !Array.isArray(value);
const date = (value: unknown): value is string =>
    typeof value === 'string' &&
    /^\d{4}-\d{2}-\d{2}$/.test(value) &&
    Number.isFinite(Date.parse(`${value}T00:00:00Z`));
function totals(value: unknown, keys: string[]): boolean {
    return record(value) && keys.every((key) => finite(value[key]));
}
function rows(value: unknown, keys: string[], dated = false): boolean {
    return (
        Array.isArray(value) &&
        value.every(
            (row) =>
                record(row) &&
                (dated ? date(row.date) : typeof row.name === 'string') &&
                keys.every((key) => finite(row[key])),
        )
    );
}
function ga4(value: unknown): boolean {
    if (value === null) return true;
    if (!record(value)) return false;
    return (
        totals(value.totals, [
            'users',
            'sessions',
            'views',
            'engagement_rate',
        ]) &&
        totals(value.previous, [
            'users',
            'sessions',
            'views',
            'engagement_rate',
        ]) &&
        rows(value.daily, ['users', 'sessions', 'views'], true) &&
        rows(value.sources, ['sessions']) &&
        rows(value.pages, ['views']) &&
        rows(value.countries, ['users']) &&
        rows(value.devices, ['users']) &&
        (value.timezone === null || typeof value.timezone === 'string')
    );
}
function gsc(value: unknown): boolean {
    if (value === null) return true;
    if (!record(value)) return false;
    const keys = ['clicks', 'impressions', 'ctr', 'position'];
    return (
        totals(value.totals, keys) &&
        totals(value.previous, keys) &&
        rows(value.daily, ['clicks', 'impressions'], true) &&
        rows(value.queries, keys) &&
        rows(value.pages, keys)
    );
}
export function readTrafficReports(value: unknown): TrafficReport[] {
    if (
        !Array.isArray(value) ||
        !value.every(
            (report) =>
                record(report) &&
                Number.isSafeInteger(report.website_id) &&
                Number(report.website_id) > 0 &&
                typeof report.website_name === 'string' &&
                [
                    'unlinked',
                    'missing',
                    'queued',
                    'running',
                    'ready',
                    'failed',
                ].includes(String(report.status)) &&
                (report.ga4_property_id === null ||
                    typeof report.ga4_property_id === 'string') &&
                (report.gsc_site_url === null ||
                    typeof report.gsc_site_url === 'string') &&
                (report.updated_at === null ||
                    (typeof report.updated_at === 'string' &&
                        Number.isFinite(Date.parse(report.updated_at)))) &&
                (report.error === null || typeof report.error === 'string') &&
                ga4(report.ga4) &&
                gsc(report.gsc) &&
                (report.notes === undefined ||
                    (Array.isArray(report.notes) &&
                        report.notes.every(
                            (note) => typeof note === 'string',
                        ))),
        )
    )
        throw new Error('Invalid traffic report');
    if (new Set(value.map((report) => report.website_id)).size !== value.length)
        throw new Error('Duplicate traffic report');
    return value as TrafficReport[];
}

function gaTotals(
    reports: Ga4Report[],
    key: 'totals' | 'previous',
): Ga4Totals | null {
    if (!reports.length) return null;
    const total = reports.reduce(
        (sum, report) => ({
            users: sum.users + report[key].users,
            sessions: sum.sessions + report[key].sessions,
            views: sum.views + report[key].views,
            engaged:
                sum.engaged +
                report[key].engagement_rate * report[key].sessions,
        }),
        { users: 0, sessions: 0, views: 0, engaged: 0 },
    );
    return {
        users: total.users,
        sessions: total.sessions,
        views: total.views,
        engagement_rate: total.sessions ? total.engaged / total.sessions : 0,
    };
}
function searchTotals(
    reports: GscReport[],
    key: 'totals' | 'previous',
): SearchTotals | null {
    if (!reports.length) return null;
    const total = reports.reduce(
        (sum, report) => ({
            clicks: sum.clicks + report[key].clicks,
            impressions: sum.impressions + report[key].impressions,
            positionWeight:
                sum.positionWeight +
                report[key].position * report[key].impressions,
        }),
        { clicks: 0, impressions: 0, positionWeight: 0 },
    );
    return {
        clicks: total.clicks,
        impressions: total.impressions,
        ctr: total.impressions ? total.clicks / total.impressions : 0,
        position: total.impressions
            ? total.positionWeight / total.impressions
            : 0,
    };
}
export function trafficSummary(reports: TrafficReport[]) {
    const ga = reports.flatMap((report) =>
        report.ga4 && !report.ga4.error ? [report.ga4] : [],
    );
    const search = reports.flatMap((report) =>
        report.gsc && !report.gsc.error ? [report.gsc] : [],
    );
    return {
        ga4: gaTotals(ga, 'totals'),
        ga4Previous: gaTotals(ga, 'previous'),
        gsc: searchTotals(search, 'totals'),
        gscPrevious: searchTotals(search, 'previous'),
        ga4Count: ga.length,
        gscCount: search.length,
    };
}
export function trafficBreakdown(
    reports: TrafficReport[],
    kind: 'sources' | 'pages' | 'countries' | 'devices',
): Array<{ name: string; value: number }> {
    const key =
        kind === 'sources' ? 'sessions' : kind === 'pages' ? 'views' : 'users';
    const combined = new Map<string, number>();
    for (const report of reports) {
        if (!report.ga4 || report.ga4.error) continue;
        for (const row of report.ga4[kind]) {
            const value = Number(
                (row as unknown as Record<string, number>)[key],
            );
            combined.set(row.name, (combined.get(row.name) ?? 0) + value);
        }
    }
    return [...combined]
        .map(([name, value]) => ({ name, value }))
        .sort((a, b) => b.value - a.value || a.name.localeCompare(b.name))
        .slice(0, 20);
}
export function trafficSearchRows(
    reports: TrafficReport[],
    kind: 'queries' | 'pages',
): SearchRow[] {
    const combined = new Map<
        string,
        { clicks: number; impressions: number; weightedPosition: number }
    >();
    for (const report of reports) {
        if (!report.gsc || report.gsc.error) continue;
        for (const row of report.gsc[kind]) {
            const current = combined.get(row.name) ?? {
                clicks: 0,
                impressions: 0,
                weightedPosition: 0,
            };
            current.clicks += row.clicks;
            current.impressions += row.impressions;
            current.weightedPosition += row.position * row.impressions;
            combined.set(row.name, current);
        }
    }
    return [...combined]
        .map(([name, row]) => ({
            name,
            clicks: row.clicks,
            impressions: row.impressions,
            ctr: row.impressions ? row.clicks / row.impressions : 0,
            position: row.impressions
                ? row.weightedPosition / row.impressions
                : 0,
        }))
        .sort(
            (a, b) =>
                b.clicks - a.clicks ||
                b.impressions - a.impressions ||
                a.name.localeCompare(b.name),
        )
        .slice(0, 50);
}
export function trafficDaily(
    reports: TrafficReport[],
    metric: 'sessions' | 'clicks',
): Array<{ date: string; value: number }> {
    const combined = new Map<string, number>();
    for (const report of reports) {
        const source = metric === 'sessions' ? report.ga4 : report.gsc;
        if (!source || source.error) continue;
        for (const row of source.daily)
            combined.set(
                row.date,
                (combined.get(row.date) ?? 0) +
                    Number((row as unknown as Record<string, number>)[metric]),
            );
    }
    return [...combined]
        .map(([date, value]) => ({ date, value }))
        .sort((a, b) => a.date.localeCompare(b.date));
}
export function trafficComparison(
    current: number | null,
    previous: number | null,
    rate = false,
): string {
    if (current === null || previous === null) return 'Comparison unavailable';
    if (rate) {
        const delta = (current - previous) * 100;
        return `${delta > 0 ? '+' : ''}${delta.toFixed(1)} pp vs previous period`;
    }
    if (!previous)
        return current
            ? 'No previous baseline'
            : 'No change vs previous period';
    const delta = ((current - previous) / previous) * 100;
    return `${delta > 0 ? '+' : ''}${delta.toFixed(1)}% vs previous period`;
}
export function trafficNeedsRefresh(
    reports: TrafficReport[],
    now = Date.now(),
): boolean {
    return reports.some(
        (report) =>
            report.status === 'missing' ||
            (report.status === 'ready' &&
                (!report.updated_at ||
                    now - Date.parse(report.updated_at) >= 3_600_000)),
    );
}
export function trafficPending(reports: TrafficReport[]): boolean {
    return reports.some(
        (report) => report.status === 'queued' || report.status === 'running',
    );
}
export function trafficRange(
    days: number,
    now = new Date(),
): { start_date: string; end_date: string } {
    const end = new Date(
        Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate() - 1),
    );
    const start = new Date(end);
    start.setUTCDate(start.getUTCDate() - (days - 1));
    return {
        start_date: start.toISOString().slice(0, 10),
        end_date: end.toISOString().slice(0, 10),
    };
}

/** Only queued work is checked. One explicit run is capped at two minutes. */
export function createTrafficPoller(options: {
    read: (signal: AbortSignal) => Promise<TrafficReport[]>;
    apply: (reports: TrafficReport[]) => void;
    available: () => boolean;
    error: (message: string) => void;
    waiting: (value: boolean) => void;
    now?: () => number;
    setTimer?: typeof setTimeout;
    clearTimer?: typeof clearTimeout;
}) {
    const now = options.now ?? Date.now;
    const setTimer = options.setTimer ?? setTimeout;
    const clearTimer = options.clearTimer ?? clearTimeout;
    let timer: ReturnType<typeof setTimeout> | null = null;
    let cutoff: ReturnType<typeof setTimeout> | null = null;
    let request: AbortController | null = null;
    let generation = 0,
        deadline = 0;
    function clearCutoff() {
        if (cutoff !== null) clearTimer(cutoff);
        cutoff = null;
    }
    function pause() {
        generation++;
        if (timer !== null) clearTimer(timer);
        timer = null;
        clearCutoff();
        request?.abort();
        request = null;
        options.waiting(false);
    }
    function armDeadline() {
        clearCutoff();
        if (!options.available() || now() >= deadline) return;
        cutoff = setTimer(() => {
            pause();
            options.error(
                'Reports are still being prepared. Use Refresh reports to check again later.',
            );
        }, deadline - now());
    }
    async function read() {
        if (!options.available()) return;
        if (now() >= deadline) {
            options.waiting(false);
            options.error(
                'Reports are still being prepared. Use Refresh reports to check again later.',
            );
            return;
        }
        const token = generation;
        const controller = new AbortController();
        request = controller;
        options.waiting(true);
        try {
            const reports = await options.read(controller.signal);
            if (generation !== token || !options.available()) return;
            options.apply(reports);
            if (trafficPending(reports))
                timer = setTimer(
                    () => {
                        timer = null;
                        void read();
                    },
                    Math.min(4_000, Math.max(0, deadline - now())),
                );
            else {
                clearCutoff();
                options.waiting(false);
            }
        } catch {
            if (generation === token && options.available()) {
                options.waiting(false);
                clearCutoff();
                options.error(
                    'Report progress could not be checked. Use Refresh reports to try again.',
                );
            }
        } finally {
            if (request === controller) request = null;
        }
    }
    return {
        start() {
            pause();
            deadline = now() + 120_000;
            armDeadline();
            void read();
        },
        resume() {
            if (deadline && !request && timer === null) {
                armDeadline();
                void read();
            }
        },
        pause,
        stop() {
            pause();
            deadline = 0;
        },
    };
}
