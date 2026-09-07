<?php

namespace App\Jobs;

use App\Models\Website;

/** Compatibility entry point for schema-triggered imports. */
class SyncFluentFormEntries extends SyncFluentSubmissions
{
    public function __construct(Website $website, int $formId, int $startPage = 1)
    {
        parent::__construct($website->id, $formId, 100, $startPage);
    }
}
