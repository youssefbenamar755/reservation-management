export interface OrderAlert {
    id: string;
    message: string;
    url: string;
}

interface OrderNotification {
    id: string;
    type: string;
    message: string;
    read_at?: string | null;
    data: { order_id?: unknown; [key: string]: unknown };
}

export function createOrderAlerts(options: {
    onChange: (alerts: OrderAlert[]) => void;
    onSound: () => void;
    duration?: number;
}) {
    let alerts: OrderAlert[] = [];
    const seen = new Set<string>();
    const timers = new Map<string, ReturnType<typeof setTimeout>>();
    let disposed = false;

    function dismiss(id: string) {
        clearTimeout(timers.get(id));
        timers.delete(id);
        alerts = alerts.filter((alert) => alert.id !== id);
        if (!disposed) options.onChange([...alerts]);
    }

    return {
        receive(notification: OrderNotification) {
            const orderId = Number(notification.data?.order_id);
            if (disposed || notification.type !== 'order' || notification.read_at || !notification.id
                || !Number.isSafeInteger(orderId) || orderId <= 0 || seen.has(notification.id)) return;
            seen.add(notification.id);
            if (seen.size > 500) seen.delete(seen.values().next().value!);
            if (alerts.length >= 3) dismiss(alerts[0].id);
            alerts.push({ id: notification.id, message: notification.message, url: `/orders/${orderId}` });
            timers.set(notification.id, setTimeout(() => dismiss(notification.id), options.duration ?? 12000));
            options.onChange([...alerts]);
            options.onSound();
        },
        dismiss,
        dispose() {
            disposed = true;
            timers.forEach(clearTimeout);
            timers.clear();
            alerts = [];
            seen.clear();
        },
    };
}
