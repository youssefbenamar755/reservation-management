<?php

namespace App\Exceptions;

use RuntimeException;

/** Only sanitized, operator-facing store synchronization errors use this type. */
class DocumentStoreException extends RuntimeException {}
