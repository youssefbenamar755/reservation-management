<?php

namespace App\Exceptions;

use RuntimeException;

class GmailDeliveryException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $uncertain = false)
    {
        // Never retain a provider response or HTTP exception containing tokens/MIME.
        parent::__construct($message);
    }
}
