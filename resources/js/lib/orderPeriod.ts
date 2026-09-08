export type OrderPeriod = 'all' | 'today' | '7d' | '30d' | 'month';

export function orderPeriodRange(
    period: OrderPeriod,
    timezone = 'UTC',
    now = new Date(),
): { start_date: string; end_date: string } {
    if (period === 'all') return { start_date: '', end_date: '' };
    const parts = new Intl.DateTimeFormat('en-US', {
        timeZone: timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(now);
    const part = (type: string) =>
        parts.find((item) => item.type === type)!.value;
    const end_date = `${part('year')}-${part('month')}-${part('day')}`;
    // Calendar arithmetic in UTC avoids DST shifts in the reporting timezone.
    const start = new Date(`${end_date}T00:00:00Z`);
    if (period === 'month') start.setUTCDate(1);
    else
        start.setUTCDate(
            start.getUTCDate() -
                (period === '7d' ? 6 : period === '30d' ? 29 : 0),
        );
    return { start_date: start.toISOString().slice(0, 10), end_date };
}

export function orderPeriodLabel(
    start?: string | null,
    end?: string | null,
): string {
    const format = (day: string) =>
        new Intl.DateTimeFormat('en-US', {
            timeZone: 'UTC',
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        }).format(new Date(`${day}T00:00:00Z`));
    if (start && end)
        return start === end
            ? format(start)
            : `${format(start)} – ${format(end)}`;
    if (start) return `From ${format(start)}`;
    if (end) return `Through ${format(end)}`;
    return 'All time';
}
