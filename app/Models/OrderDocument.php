<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OrderDocument extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['disk', 'path', 'customer_email'];

    protected $casts = ['approved_at' => 'datetime', 'size' => 'integer', 'version' => 'integer'];

    public function summary(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'size' => $this->size, 'sha256' => $this->sha256,
            'version' => $this->version, 'approved' => $this->approved_at !== null];
    }
}
