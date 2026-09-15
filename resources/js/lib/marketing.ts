export const marketingSources = [
    { value: 'all', label: 'All sources' },
    { value: 'forms', label: 'Fluent Forms contacts' },
    { value: 'orders', label: 'Order customers' },
    { value: 'forms_only', label: 'Form leads · no orders' },
    { value: 'orders_only', label: 'Order customers · no forms' },
    { value: 'both', label: 'Forms and orders' },
];
export const marketingSegments = [
    { value: 'all', label: 'All subscribers' },
    { value: 'first', label: 'One completed order' },
    { value: 'repeat', label: 'Repeat customers (2+ completed)' },
    { value: 'inactive', label: 'Last order over 90 days ago' },
];
export const marketingStatus = (value: string) =>
    ({
        draft: 'Draft',
        scheduled: 'Scheduled',
        preparing: 'Preparing audience',
        sending: 'Sending request',
        submitted: 'Accepted by Brevo',
        uncertain: 'Needs verification',
        failed: 'Needs attention',
        cancelled: 'Cancelled',
        unknown: 'No permission',
        subscribed: 'Subscribed',
        unsubscribed: 'Unsubscribed',
    })[value] || value;
export const marketingDate = (value: string | null) =>
    value
        ? new Date(
              value.endsWith('Z') || /[+-]\d\d:\d\d$/.test(value)
                  ? value
                  : value.replace(' ', 'T') + 'Z',
          ).toLocaleString(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : '—';
export const marketingTone = (value: string) =>
    ['submitted', 'subscribed'].includes(value)
        ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
        : ['uncertain', 'failed', 'unsubscribed'].includes(value)
          ? 'bg-amber-50 text-amber-900 dark:bg-amber-950 dark:text-amber-300'
          : 'bg-muted text-muted-foreground';
