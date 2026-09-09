import type {
    SeoOpportunityRow,
    SeoOpportunityType,
    SeoReport,
} from '@/types/seo';

export const seoCategories: Array<{
    key: SeoOpportunityType;
    label: string;
    description: string;
}> = [
    {
        key: 'declining',
        label: 'Losing clicks',
        description:
            'Comparable rows with at least 5 fewer clicks and a 20% decline.',
    },
    {
        key: 'low_ctr',
        label: 'Low CTR pages',
        description:
            'Pages with 100+ impressions, average position 1–10, and CTR below 2%.',
    },
    {
        key: 'near_page_one',
        label: 'Near page one',
        description:
            'Queries with 100+ impressions and average position above 10 through 20.',
    },
];
const object = (value: unknown): value is Record<string, unknown> =>
    !!value && typeof value === 'object' && !Array.isArray(value);
const finite = (value: unknown): value is number =>
    typeof value === 'number' && Number.isFinite(value);
const count = (value: unknown): value is number =>
    Number.isSafeInteger(value) && Number(value) >= 0;
export function seoDate(value: unknown): value is string {
    if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value))
        return false;
    const date = new Date(`${value}T00:00:00Z`);
    return (
        Number.isFinite(date.getTime()) &&
        date.toISOString().slice(0, 10) === value
    );
}
function metrics(value: unknown): boolean {
    return (
        object(value) &&
        count(value.clicks) &&
        count(value.impressions) &&
        finite(value.ctr) &&
        value.ctr >= 0 &&
        value.ctr <= 1 &&
        finite(value.position) &&
        value.position >= 0
    );
}
export function seoPageUrl(value: string, maxBytes = 2048): boolean {
    try {
        const url = new URL(value);
        return (
            new TextEncoder().encode(value).length <= maxBytes &&
            !/\s/u.test(value) &&
            !Array.from(value).some(
                (character) =>
                    character.charCodeAt(0) < 32 ||
                    character.charCodeAt(0) === 127,
            ) &&
            ['https:', 'http:'].includes(url.protocol) &&
            !url.username &&
            !url.password
        );
    } catch {
        return false;
    }
}
function comparison(value: unknown): boolean {
    return (
        object(value) &&
        typeof value.name === 'string' &&
        metrics(value.current) &&
        (value.previous === null || metrics(value.previous)) &&
        (value.click_change === null || finite(value.click_change)) &&
        (value.previous !== null || value.click_change === null)
    );
}
function payload(value: unknown): boolean {
    if (value === null) return true;
    if (
        !object(value) ||
        !object(value.coverage) ||
        !['queries', 'pages', 'previous_queries', 'previous_pages'].every(
            (key) => count((value.coverage as Record<string, unknown>)[key]),
        ) ||
        value.coverage.row_limit !== 1000 ||
        !seoDate(value.previous_start_date) ||
        !seoDate(value.previous_end_date) ||
        value.previous_start_date > value.previous_end_date ||
        !Array.isArray(value.notes) ||
        !value.notes.every((note) => typeof note === 'string') ||
        !Array.isArray(value.queries) ||
        value.queries.length > 1000 ||
        !value.queries.every(comparison) ||
        !Array.isArray(value.opportunities) ||
        value.opportunities.length > 500 ||
        !count(value.opportunity_count) ||
        value.opportunity_count < value.opportunities.length
    )
        return false;
    const ids = new Set<string>();
    return value.opportunities.every((row) => {
        if (
            !object(row) ||
            !comparison(row) ||
            typeof row.id !== 'string' ||
            !row.id ||
            ids.has(row.id) ||
            !seoCategories.some((category) => category.key === row.type) ||
            !['query', 'page'].includes(String(row.dimension)) ||
            (row.dimension === 'page' && !seoPageUrl(String(row.name), 4096)) ||
            typeof row.reason !== 'string' ||
            typeof row.action !== 'string' ||
            (row.decline_percent !== null &&
                (!finite(row.decline_percent) ||
                    row.decline_percent < 0 ||
                    row.decline_percent > 100)) ||
            (row.previous === null && row.decline_percent !== null)
        )
            return false;
        ids.add(row.id);
        return true;
    });
}
export function readSeoReport(value: unknown): SeoReport {
    if (
        !object(value) ||
        !count(value.website_id) ||
        !value.website_id ||
        typeof value.website_name !== 'string' ||
        (value.gsc_site_url !== null &&
            typeof value.gsc_site_url !== 'string') ||
        ![
            'unlinked',
            'missing',
            'queued',
            'running',
            'ready',
            'failed',
        ].includes(String(value.status)) ||
        (value.updated_at !== null &&
            (typeof value.updated_at !== 'string' ||
                !Number.isFinite(Date.parse(value.updated_at)))) ||
        (value.error !== null && typeof value.error !== 'string') ||
        !payload(value.data)
    )
        throw new Error('Invalid SEO report');
    return value as unknown as SeoReport;
}
export function readSeoReports(value: unknown): SeoReport[] {
    if (!Array.isArray(value)) throw new Error('Invalid SEO reports');
    const reports = value.map(readSeoReport);
    if (
        new Set(reports.map((report) => report.website_id)).size !==
        reports.length
    )
        throw new Error('Duplicate SEO report');
    return reports;
}
export function seoOpportunities(reports: SeoReport[]): SeoOpportunityRow[] {
    const priority = (type: SeoOpportunityType) =>
        seoCategories.findIndex((category) => category.key === type);
    return reports
        .flatMap((report) =>
            (report.data?.opportunities ?? []).map((row) => ({
                ...row,
                website_id: report.website_id,
                website_name: report.website_name,
                key: `${report.website_id}:${row.id}`,
            })),
        )
        .sort(
            (a, b) =>
                priority(a.type) - priority(b.type) ||
                (a.type === 'declining'
                    ? (a.click_change ?? 0) - (b.click_change ?? 0)
                    : b.current.impressions - a.current.impressions) ||
                a.dimension.localeCompare(b.dimension) ||
                a.name.localeCompare(b.name) ||
                a.website_id - b.website_id,
        );
}
export function seoFiltered(
    rows: SeoOpportunityRow[],
    type: 'all' | SeoOpportunityType,
    search: string,
): SeoOpportunityRow[] {
    const query = search.trim().toLocaleLowerCase();
    return rows.filter(
        (row) =>
            (type === 'all' || row.type === type) &&
            (!query ||
                [row.name, row.website_name, row.reason, row.action].some(
                    (value) => value.toLocaleLowerCase().includes(query),
                )),
    );
}
export function seoCounts(reports: SeoReport[]) {
    const rows = seoOpportunities(reports);
    return {
        returned: rows.length,
        matched: reports.reduce(
            (sum, report) => sum + (report.data?.opportunity_count ?? 0),
            0,
        ),
        pages: new Set(
            rows
                .filter((row) => row.dimension === 'page')
                .map((row) => `${row.website_id}:${row.name}`),
        ).size,
        queries: new Set(
            rows
                .filter((row) => row.dimension === 'query')
                .map((row) => `${row.website_id}:${row.name}`),
        ).size,
    };
}
export function seoNeedsRefresh(
    reports: SeoReport[],
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
export function seoPending(reports: SeoReport[]): boolean {
    return reports.some(
        (report) => report.status === 'queued' || report.status === 'running',
    );
}
export function seoRange(
    days: number,
    maxEndDate: string,
): { start_date: string; end_date: string } {
    if (
        !Number.isInteger(days) ||
        days < 1 ||
        days > 93 ||
        !seoDate(maxEndDate)
    )
        throw new Error('Invalid SEO range');
    const start = new Date(`${maxEndDate}T00:00:00Z`);
    start.setUTCDate(start.getUTCDate() - (days - 1));
    return {
        start_date: start.toISOString().slice(0, 10),
        end_date: maxEndDate,
    };
}

/** A separate bounded poller allows the overview and one page analysis to cancel independently. */
export function createSeoPoller<T>(options: {
    read: (signal: AbortSignal) => Promise<T>;
    apply: (value: T) => void;
    pending: (value: T) => boolean;
    available: () => boolean;
    waiting: (value: boolean) => void;
    error: (message: string) => void;
    failure?: (error: unknown) => string;
    now?: () => number;
    setTimer?: typeof setTimeout;
    clearTimer?: typeof clearTimeout;
}) {
    const now = options.now ?? Date.now,
        setTimer = options.setTimer ?? setTimeout,
        clearTimer = options.clearTimer ?? clearTimeout;
    let generation = 0,
        deadline = 0;
    let request: AbortController | null = null;
    let timer: ReturnType<typeof setTimeout> | null = null,
        cutoff: ReturnType<typeof setTimeout> | null = null;
    const expired =
        'Analysis is still being prepared. Use Refresh analysis to check again later.';
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
    function arm() {
        clearCutoff();
        if (options.available() && deadline > now())
            cutoff = setTimer(() => {
                pause();
                options.error(expired);
            }, deadline - now());
    }
    async function read() {
        if (!options.available()) return;
        if (now() >= deadline) {
            options.waiting(false);
            options.error(expired);
            return;
        }
        const token = generation,
            controller = new AbortController();
        request = controller;
        options.waiting(true);
        try {
            const value = await options.read(controller.signal);
            if (token !== generation || !options.available()) return;
            options.apply(value);
            if (options.pending(value))
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
        } catch (error) {
            if (token === generation && options.available()) {
                clearCutoff();
                options.waiting(false);
                options.error(
                    options.failure?.(error) ??
                        'Analysis progress could not be checked. Use Refresh analysis to try again.',
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
            arm();
            void read();
        },
        resume() {
            if (deadline && !request && timer === null) {
                arm();
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
