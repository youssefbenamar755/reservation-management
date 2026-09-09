<?php

namespace App\Exceptions;

use RuntimeException;

class GoogleReportingException extends RuntimeException
{
    public function __construct(string $message)
    {
        // Never retain provider bodies or previous HTTP exceptions containing tokens.
        parent::__construct($message);
    }
}
