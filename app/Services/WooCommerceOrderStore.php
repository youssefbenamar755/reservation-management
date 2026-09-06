<?php

namespace App\Services;

use App\Models\WcOrder;
use App\Models\Website;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class WooCommerceOrderStore
{
    /** @return array{order: WcOrder, created: bool, changed: bool} */
    public function store(int $websiteId, array $payload): array
    {
        $orderId = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($orderId === false) {
            throw new InvalidArgumentException('Missing or invalid WooCommerce order ID.');
        }

        return DB::transaction(function () use ($websiteId, $orderId, $payload) {
            // Serialize sync and webhook inserts even before an order row exists.
            Website::whereKey($websiteId)->lockForUpdate()->firstOrFail();
            $order = WcOrder::firstOrNew(['website_id' => $websiteId, 'wp_order_id' => $orderId]);
            $created = ! $order->exists;
            $modifiedAt = ! empty($payload['date_modified_gmt'])
                ? Carbon::parse($payload['date_modified_gmt'], 'UTC')
                : null;

            // Undated or delayed deliveries must not replace a known newer state.
            if (! $created && $order->updated_at_wp && (! $modifiedAt || $modifiedAt->lt($order->updated_at_wp))) {
                return ['order' => $order, 'created' => false, 'changed' => false];
            }

            $order->fill([
                'status' => $payload['status'] ?? 'unknown',
                'payment_status' => $payload['payment_status'] ?? (! empty($payload['date_paid']) ? 'paid' : null),
                'currency' => $payload['currency'] ?? null,
                'total' => $payload['total'] ?? 0,
                'customer_email' => data_get($payload, 'billing.email'),
                'customer_name' => trim((data_get($payload, 'billing.first_name') ?? '').' '.(data_get($payload, 'billing.last_name') ?? '')),
                'created_at_wp' => ! empty($payload['date_created_gmt']) ? Carbon::parse($payload['date_created_gmt'], 'UTC') : null,
                'updated_at_wp' => $modifiedAt,
                'payload' => $payload,
            ]);
            $changed = $order->isDirty();
            $order->save();

            return compact('order', 'created', 'changed');
        }, 3);
    }
}
