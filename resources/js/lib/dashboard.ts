import type { DashboardComparison } from '@/types/dashboard';

export function dashboardMoney(
    value: number | string,
    currency: string,
): string {
    const amount = Number(value);
    if (!Number.isFinite(amount)) return '—';
    try {
        if (!/^[A-Z]{3}$/.test(currency)) throw new Error('Unknown currency');
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency,
            currencyDisplay: 'symbol',
            maximumFractionDigits: 2,
        }).format(amount);
    } catch {
        return `${amount.toLocaleString('en-US', { maximumFractionDigits: 2 })} ${currency || 'unknown currency'}`;
    }
}

export function dashboardDay(date: string): string {
    const value = new Date(`${date.slice(0, 10)}T00:00:00Z`);
    return Number.isNaN(value.getTime())
        ? '—'
        : value.toLocaleDateString('en-US', {
              month: 'short',
              day: 'numeric',
              timeZone: 'UTC',
          });
}

export function dashboardChange(metric: DashboardComparison): string {
    if (metric.change_percent === null)
        return metric.current > 0 ? 'New this period' : 'No change';
    if (metric.change_percent === 0) return 'No change';
    return `${metric.change_percent > 0 ? '+' : ''}${metric.change_percent.toLocaleString('en-US', { maximumFractionDigits: 1 })}%`;
}

export function dashboardStatus(status: string): string {
    return status
        .replaceAll('-', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

export function dashboardOrderUrl(
    websiteId: number | null,
    status?: string,
): string {
    const params = new URLSearchParams();
    if (websiteId) params.set('website_id', String(websiteId));
    if (status) params.set('status', status);
    return `/orders${params.size ? `?${params}` : ''}`;
}
