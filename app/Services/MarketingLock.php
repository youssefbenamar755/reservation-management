<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class MarketingLock
{
    public function run(int $userId, callable $callback): mixed
    {
        $lock = Cache::lock('marketing-user:'.$userId, 180);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['marketing' => 'A marketing operation is in progress. Please try again shortly.']);
        }
        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
