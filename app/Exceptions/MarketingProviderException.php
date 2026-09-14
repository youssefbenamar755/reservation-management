<?php

namespace App\Exceptions;

use RuntimeException;

class MarketingProviderException extends RuntimeException
{
    public function __construct(public readonly bool $uncertain = false, public readonly int $httpStatus = 0)
    {
        parent::__construct($uncertain ? 'Brevo did not confirm the request. Check its campaign dashboard before taking further action.' : 'Brevo could not complete the request. Check your connection, verified sender, account limits and campaign in Brevo.');
    }
}
