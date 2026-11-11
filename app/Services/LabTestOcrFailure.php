<?php

namespace App\Services;

use RuntimeException;

/** An OCR failure with a client-facing error code; the message is for logs only. */
class LabTestOcrFailure extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
