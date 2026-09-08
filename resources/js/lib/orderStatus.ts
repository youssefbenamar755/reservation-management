export const editableOrderStatuses = [
    { value: 'pending', label: 'Pending payment' },
    { value: 'on-hold', label: 'On hold' },
    { value: 'processing', label: 'Processing' },
    { value: 'completed', label: 'Completed' },
    { value: 'cancelled', label: 'Cancelled' },
    { value: 'refunded', label: 'Refunded' },
    { value: 'failed', label: 'Failed' },
] as const;

export interface ConfirmedOrderStatus {
    id: number;
    status: string;
}

export function orderStatusName(status: string): string {
    return (
        editableOrderStatuses.find((item) => item.value === status)?.label ??
        status
    );
}

export function isEditableOrderStatus(status: string): boolean {
    return editableOrderStatuses.some((item) => item.value === status);
}

export function orderStatusClasses(status: string): string {
    const colors: Record<string, string> = {
        completed: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
        processing: 'bg-indigo-500/10 text-indigo-700 dark:text-indigo-300',
        pending: 'bg-amber-500/10 text-amber-800 dark:text-amber-300',
        'on-hold': 'bg-orange-500/10 text-orange-700 dark:text-orange-300',
        cancelled: 'bg-zinc-500/10 text-zinc-700 dark:text-zinc-300',
        refunded: 'bg-violet-500/10 text-violet-700 dark:text-violet-300',
        failed: 'bg-rose-500/10 text-rose-700 dark:text-rose-300',
    };
    return colors[status] ?? 'bg-muted text-muted-foreground';
}

export function readConfirmedOrderStatus(
    value: unknown,
    id: number,
): ConfirmedOrderStatus | null {
    const order = value as ConfirmedOrderStatus | null;
    if (
        !order ||
        order.id !== id ||
        typeof order.status !== 'string' ||
        !order.status.trim()
    )
        return null;
    return { id: order.id, status: order.status };
}
