<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OrderDocument extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['disk', 'path', 'customer_email'];

    protected $casts = ['approved_at' => 'datetime', 'size' => 'integer', 'version' => 'integer', 'source_metadata' => 'array'];

    public function summary(): array
    {
        $summary = ['id' => $this->id, 'name' => $this->name, 'size' => $this->size, 'sha256' => $this->sha256,
            'version' => $this->version, 'approved' => $this->approved_at !== null];
        if ($this->source_version !== null) {
            $summary += ['source' => 'submission_itinerary', 'source_version' => $this->source_version,
                'submission_id' => $this->source_metadata['submission_id'] ?? null,
                'download_url' => '/orders/'.$this->wc_order_id.'/document-deliveries/documents/'.$this->id];
        }

        return $summary;
    }
}
